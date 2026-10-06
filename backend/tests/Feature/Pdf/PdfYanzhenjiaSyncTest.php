<?php

namespace Tests\Feature\Pdf;

use App\Jobs\SyncPdfToYanzhenjia;
use App\Models\PdfFile;
use App\Models\PdfYanzhenjiaSync;
use App\Services\Pdf\PdfYanzhenjiaSyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PdfYanzhenjiaSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pdf');
        config([
            'services.yanzhenjia.enabled' => true,
            'services.yanzhenjia.base_url' => 'https://www.yanzhenjia.cn',
            'services.yanzhenjia.username' => 'zdlmmm',
            'services.yanzhenjia.secret' => str_repeat('s', 32),
        ]);
        Http::preventStrayRequests();
    }

    public function test_sync_migration_leaves_a_non_unique_foreign_key_support_index(): void
    {
        $indexes = collect(Schema::getIndexes('pdf_yanzhenjia_syncs'));
        $supportIndex = $indexes->firstWhere('name', 'pdf_yanzhenjia_syncs_pdf_file_id_support_index');

        $this->assertNotNull($supportIndex);
        $this->assertSame(['pdf_file_id'], $supportIndex['columns']);
        $this->assertFalse($supportIndex['unique']);
        $this->assertTrue(Schema::hasIndex(
            'pdf_yanzhenjia_syncs',
            'pdf_yanzhenjia_sync_file_version_unique',
            'unique',
        ));
        $this->assertFalse(Schema::hasIndex('pdf_yanzhenjia_syncs', 'pdf_yanzhenjia_syncs_pdf_file_id_unique'));
    }

    public function test_completed_pdf_is_queued_and_uploaded_to_the_expected_account(): void
    {
        Queue::fake();
        $file = $this->signedFile(false);

        app(PdfYanzhenjiaSyncDispatcher::class)->enqueue($file);

        $this->assertDatabaseHas('pdf_yanzhenjia_syncs', ['pdf_file_id' => $file->id, 'status' => 'pending']);
        $this->assertSame(1, app(PdfYanzhenjiaSyncDispatcher::class)->dispatchPending());
        Queue::assertPushed(SyncPdfToYanzhenjia::class, fn (SyncPdfToYanzhenjia $job): bool => $job->pdfFileId === $file->id);

        $upload = null;
        Http::fake(function (Request $request) use (&$upload) {
            if ($request->method() === 'POST') {
                $upload = [
                    'url' => $request->url(),
                    'timestamp' => $request->header('X-Lims-Timestamp')[0],
                    'signature' => $request->header('X-Lims-Signature')[0],
                    'body' => $request->body(),
                ];
            }

            return Http::response(['success' => true, 'id' => 31, 'username' => 'zdlmmm'], 201);
        });

        (new SyncPdfToYanzhenjia($file->id))->handle();

        $sync = PdfYanzhenjiaSync::query()->sole();
        $this->assertSame('succeeded', $sync->status);
        $this->assertSame(31, $sync->remote_file_id);
        $this->assertSame('https://www.yanzhenjia.cn/api/integrations/new-lims/reports', $upload['url']);
        $payload = [
            'issued_at' => (int) $upload['timestamp'],
            'source_file_id' => $file->file_id,
            'report_number' => 'REPORT-1',
            'file_name' => 'REPORT-1.pdf',
            'file_date' => $file->signed_at->toDateString(),
            'sha256' => $file->sha256_hash,
            'md5' => $file->md5_hash,
            'file_size' => (int) $file->file_size,
        ];
        $this->assertSame(hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), str_repeat('s', 32)), $upload['signature']);
        $this->assertStringContainsString($file->file_id, $upload['body']);
        $this->assertStringContainsString($file->sha256_hash, $upload['body']);
        $this->assertStringContainsString('REPORT-1.pdf', $upload['body']);
    }

    public function test_retry_accepts_the_targets_idempotent_response_after_an_ambiguous_failure(): void
    {
        $file = $this->signedFile();
        Http::fakeSequence()
            ->push(['message' => 'temporary error'], 503)
            ->push(['success' => true, 'id' => 31, 'username' => 'zdlmmm', 'already_exists' => true]);

        try {
            (new SyncPdfToYanzhenjia($file->id))->handle();
            $this->fail('The first attempt should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }
        (new SyncPdfToYanzhenjia($file->id))->handle();

        $this->assertSame(31, PdfYanzhenjiaSync::query()->sole()->remote_file_id);
        Http::assertSentCount(2);
    }

    public function test_wrong_target_account_response_is_rejected(): void
    {
        $file = $this->signedFile();
        Http::fake(['*' => Http::response(['success' => true, 'id' => 31, 'username' => 'another-user'])]);

        try {
            (new SyncPdfToYanzhenjia($file->id))->handle();
            $this->fail('The unexpected target account should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rejected', $exception->getMessage());
        }

        $this->assertSame('failed', PdfYanzhenjiaSync::query()->sole()->status);
        Http::assertSentCount(1);
    }

    public function test_local_tampering_stops_sync_before_network_access(): void
    {
        $file = $this->signedFile();
        Storage::disk('pdf')->put($file->file_path, '%PDF-1.7 changed file');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local ledger');

        try {
            (new SyncPdfToYanzhenjia($file->id))->handle();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_deleted_local_pdf_cancels_its_pending_copy(): void
    {
        $file = $this->signedFile();
        $file->delete();

        (new SyncPdfToYanzhenjia($file->id))->handle();

        $this->assertDatabaseCount('pdf_yanzhenjia_syncs', 0);
        Http::assertNothingSent();
    }

    private function signedFile(bool $enqueue = true): PdfFile
    {
        $bytes = '%PDF-1.7 signed report';
        Storage::disk('pdf')->put('signed/report.pdf', $bytes);

        $file = PdfFile::query()->create([
            'file_id' => 'REV-0001',
            'file_name' => 'source.pdf',
            'file_path' => 'signed/report.pdf',
            'sha256_hash' => hash('sha256', $bytes),
            'md5_hash' => hash('md5', $bytes),
            'cover_report_number' => 'REPORT-1',
            'file_size' => strlen($bytes),
            'signed_at' => now(),
            'created_by' => 'Operator',
        ]);

        if ($enqueue) {
            app(PdfYanzhenjiaSyncDispatcher::class)->enqueue($file);
        }

        return $file;
    }
}

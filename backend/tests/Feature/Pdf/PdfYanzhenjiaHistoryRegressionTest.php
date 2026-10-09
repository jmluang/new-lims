<?php

namespace Tests\Feature\Pdf;

use App\Jobs\SyncPdfToYanzhenjia;
use App\Models\PdfFile;
use App\Models\PdfYanzhenjiaSetting;
use App\Models\PdfYanzhenjiaSync;
use App\Models\User;
use App\Services\Pdf\PdfYanzhenjiaSyncDispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PdfYanzhenjiaHistoryRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('pdf');
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'services.yanzhenjia.enabled' => true,
            'services.yanzhenjia.base_url' => 'https://www.yanzhenjia.cn',
            'services.yanzhenjia.username' => 'zdlmmm',
            'services.yanzhenjia.secret' => str_repeat('s', 32),
        ]);

        foreach (['pdf_yanzhenjia_settings.read', 'pdf_files.delete'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create();
        $user->givePermissionTo('pdf_yanzhenjia_settings.read', 'pdf_files.delete');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    public function test_legacy_success_keeps_report_identity_without_manufacturing_a_request_payload(): void
    {
        [$file, $sync] = $this->signedFile('legacy');
        $sync->update(['status' => 'succeeded', 'remote_file_id' => 31]);

        $this->assertRecentIdentity('REPORT-HISTORY', $file->sha256_hash, 'succeeded');
        $this->deleteJson("/api/pdf/files/{$file->id}")->assertOk();

        $this->assertRecentIdentity('REPORT-HISTORY', $file->sha256_hash, 'succeeded');
        $this->assertNull($sync->fresh()->request_payload);
        $this->assertSame(31, $sync->fresh()->remote_file_id);
        $this->assertSame('REPORT-HISTORY', $sync->fresh()->source_report_number);
        $this->assertSame($file->sha256_hash, $sync->fresh()->source_sha256);
        $this->assertNull($sync->fresh()->pdf_file_id);
    }

    public function test_v1_deletion_keeps_the_frozen_request_unchanged_and_authoritative(): void
    {
        [$file, $sync] = $this->signedFile('v1');
        $payload = $sync->request_payload;
        $payload['report_number'] = 'FROZEN-REPORT';
        $payload['sha256'] = hash('sha256', 'frozen identity');
        $sync->update(['request_payload' => $payload, 'status' => 'succeeded']);
        $storedPayload = DB::table('pdf_yanzhenjia_syncs')->where('id', $sync->id)->value('request_payload');

        $this->deleteJson("/api/pdf/files/{$file->id}")->assertOk();

        $this->assertSame($storedPayload, DB::table('pdf_yanzhenjia_syncs')->where('id', $sync->id)->value('request_payload'));
        $this->assertRecentIdentity('FROZEN-REPORT', $payload['sha256'], 'succeeded');
        $this->assertSame($file->sha256_hash, $sync->fresh()->source_sha256);
    }

    #[DataProvider('waitingStates')]
    public function test_deleted_waiting_copy_is_never_dispatched_or_sent(string $version, string $status): void
    {
        [$file, $sync] = $this->signedFile($version);
        $sync->update(['status' => $status]);

        $this->deleteJson("/api/pdf/files/{$file->id}")->assertOk();
        $this->travel(31)->minutes();

        $this->assertSame(0, app(PdfYanzhenjiaSyncDispatcher::class)->dispatchPending());
        (new SyncPdfToYanzhenjia($file->id, $version))->handle();

        $this->assertNotNull($sync->fresh()->source_deleted_at);
        $this->assertSame($status, $sync->fresh()->status); // UI derives cancellation from the deletion marker.
        $this->assertSame(0, $sync->fresh()->attempts);
        $this->assertRecentIdentity('REPORT-HISTORY', $file->sha256_hash, $status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('apiVersions')]
    public function test_confirmed_in_flight_success_survives_source_deletion(string $version): void
    {
        [$file, $sync] = $this->signedFile($version);
        Http::fake(function () use ($file, $sync, $version) {
            $this->deleteJson("/api/pdf/files/{$file->id}")->assertOk();
            $this->assertSame('running', $sync->fresh()->status);
            $this->assertNotNull($sync->fresh()->source_deleted_at);

            return Http::response($version === 'v1'
                ? ['success' => true, 'id' => 31, 'source_file_id' => $file->file_id, 'hash_verified' => false]
                : ['success' => true, 'id' => 31, 'username' => 'zdlmmm'], 201);
        });

        (new SyncPdfToYanzhenjia($file->id, $version))->handle();

        $this->assertSame('succeeded', $sync->fresh()->status);
        $this->assertSame(31, $sync->fresh()->remote_file_id);
        $this->assertNotNull($sync->fresh()->source_deleted_at);
        $this->assertRecentIdentity('REPORT-HISTORY', $file->sha256_hash, 'succeeded');
        (new SyncPdfToYanzhenjia($file->id, $version))->handle();
        $this->assertSame(0, app(PdfYanzhenjiaSyncDispatcher::class)->dispatchPending());
        Http::assertSentCount(1);
    }

    #[DataProvider('apiVersions')]
    public function test_in_flight_failure_after_deletion_is_not_retried(string $version): void
    {
        [$file, $sync] = $this->signedFile($version);
        Http::fake(function () use ($file) {
            $this->deleteJson("/api/pdf/files/{$file->id}")->assertOk();

            return Http::response(['message' => 'ambiguous upstream failure'], 503);
        });

        try {
            (new SyncPdfToYanzhenjia($file->id, $version))->handle();
            $this->fail('The failed response should be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }

        $this->assertSame('failed', $sync->fresh()->status);
        $this->assertNotNull($sync->fresh()->source_deleted_at);
        $this->assertRecentIdentity('REPORT-HISTORY', $file->sha256_hash, 'failed');
        $this->travel(31)->minutes();
        (new SyncPdfToYanzhenjia($file->id, $version))->handle();
        $this->assertSame(0, app(PdfYanzhenjiaSyncDispatcher::class)->dispatchPending());
        Http::assertSentCount(1);
    }

    public function test_deletion_after_a_worker_reads_the_sync_does_not_lose_its_history(): void
    {
        [$file, $sync] = $this->signedFile('legacy');
        $deleted = false;
        PdfYanzhenjiaSync::retrieved(function (PdfYanzhenjiaSync $row) use ($file, $sync, &$deleted): void {
            if (! $deleted && $row->id === $sync->id) {
                $deleted = true;
                $this->deleteJson("/api/pdf/files/{$file->id}")->assertOk();
            }
        });

        try {
            (new SyncPdfToYanzhenjia($file->id, 'legacy'))->handle();
            $this->fail('The source PDF has been deleted.');
        } catch (ModelNotFoundException) {
            $this->assertTrue($deleted);
        }

        $this->assertSame('failed', $sync->fresh()->status);
        $this->assertNull($sync->fresh()->pdf_file_id);
        $this->assertNotNull($sync->fresh()->source_deleted_at);
        $this->assertRecentIdentity('REPORT-HISTORY', $file->sha256_hash, 'failed');
        $this->assertSame(0, app(PdfYanzhenjiaSyncDispatcher::class)->dispatchPending());
        Http::assertNothingSent();
    }

    public static function waitingStates(): array
    {
        return [['legacy', 'pending'], ['legacy', 'queued'], ['v1', 'pending'], ['v1', 'queued']];
    }

    public static function apiVersions(): array
    {
        return [['legacy'], ['v1']];
    }

    private function signedFile(string $version): array
    {
        if ($version === 'v1') {
            PdfYanzhenjiaSetting::query()->create([
                'id' => PdfYanzhenjiaSetting::SINGLETON_ID,
                'enabled' => true,
                'appid' => str_repeat('a', 32),
                'secret' => 'test-company-secret',
            ]);
        }
        $bytes = '%PDF-1.7 history regression';
        Storage::disk('pdf')->put('signed/history.pdf', $bytes);
        $file = PdfFile::query()->create([
            'file_id' => 'SYNC-HISTORY',
            'file_name' => 'history.pdf',
            'file_path' => 'signed/history.pdf',
            'cover_report_number' => 'REPORT-HISTORY',
            'sha256_hash' => hash('sha256', $bytes),
            'md5_hash' => hash('md5', $bytes),
            'file_size' => strlen($bytes),
            'signed_at' => now(),
            'created_by' => 'Operator',
        ]);
        app(PdfYanzhenjiaSyncDispatcher::class)->enqueue($file);

        return [$file, PdfYanzhenjiaSync::query()->sole()];
    }

    private function assertRecentIdentity(string $reportNumber, string $sha256, string $status): void
    {
        $this->getJson('/api/pdf/yanzhenjia-settings/recent-syncs')->assertOk()
            ->assertJsonPath('data.0.file_id', 'SYNC-HISTORY')
            ->assertJsonPath('data.0.file_name', 'history.pdf')
            ->assertJsonPath('data.0.report_number', $reportNumber)
            ->assertJsonPath('data.0.sha256', $sha256)
            ->assertJsonPath('data.0.status', $status);
    }
}

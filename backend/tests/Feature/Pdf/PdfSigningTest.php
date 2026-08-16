<?php

namespace Tests\Feature\Pdf;

use App\Models\DigitalSignature;
use App\Models\HomepageFunctionStamp;
use App\Models\PdfFile;
use App\Models\PerforationStamp;
use App\Models\User;
use App\Services\Pdf\PdfRendererClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PdfSigningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config([
            'pdf_service.enabled' => true,
            'pdf_service.signing.enabled' => true,
            'pdf_service.signing.photometric_removal_enabled' => false,
        ]);
    }

    public function test_options_expose_active_configurations_and_feature_flags(): void
    {
        $this->seal(DigitalSignature::class, ['name' => '检测专用章', 'is_default' => true]);
        $this->seal(DigitalSignature::class, ['name' => '停用章', 'is_active' => false]);
        $this->seal(PerforationStamp::class, ['name' => '骑缝章']);

        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.read']));

        $this->getJson('/api/pdf/signing/options')
            ->assertOk()
            ->assertJsonCount(1, 'data.digital_signatures')
            ->assertJsonPath('data.digital_signatures.0.name', '检测专用章')
            ->assertJsonPath('data.perforation_stamps.0.name', '骑缝章')
            ->assertJsonPath('meta.signing_enabled', true)
            ->assertJsonPath('meta.photometric_removal_enabled', false);
    }

    public function test_signing_stamps_the_upload_and_records_its_digests(): void
    {
        Storage::fake('pdf');

        $signature = $this->seal(DigitalSignature::class, ['name' => '检测专用章']);
        $perforation = $this->seal(PerforationStamp::class, ['name' => '骑缝章']);
        $functionStamp = $this->functionStamp('CMA');

        $signedBytes = '%PDF-1.7 signed output';
        $this->fakeRendererReturning($signedBytes, ['report_number' => 'ZS-2026-0007']);

        $user = $this->userWithPermissions(['pdf_signing.create']);
        Sanctum::actingAs($user);

        $response = $this->post('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 source'),
            'original_name' => 'report.pdf',
            'digital_signature_id' => $signature->id,
            'perforation_stamp_id' => $perforation->id,
            'function_stamp_ids' => [$functionStamp->id],
        ]);

        $response->assertOk();
        $this->assertSame(hash('sha256', $signedBytes), $response->headers->get('X-Final-File-Hash'));
        $this->assertSame('ZS-2026-0007', $response->headers->get('X-Cover-Report-Number'));

        $record = PdfFile::query()->sole();
        $this->assertSame(hash('sha256', $signedBytes), $record->sha256_hash);
        $this->assertSame(hash('md5', $signedBytes), $record->md5_hash);
        $this->assertSame(strlen($signedBytes), $record->file_size);
        $this->assertSame('ZS-2026-0007', $record->cover_report_number);
        $this->assertSame($user->name, $record->created_by);
        $this->assertTrue($record->metadata['signed']);
        $this->assertSame([$functionStamp->id], $record->metadata['function_stamp_ids']);

        // Names are snapshotted so the ledger still explains a signing after the
        // seal configuration has been deleted.
        $this->assertSame('检测专用章', $record->metadata['digital_signature_name']);
        $this->assertSame('骑缝章', $record->metadata['perforation_stamp_name']);
        $this->assertSame(['CMA'], $record->metadata['function_stamp_names']);
        $this->assertSame('cover_extraction', $record->metadata['report_number_source']);
        Storage::disk('pdf')->assertExists($record->file_path);
    }

    public function test_the_response_carries_a_link_the_browser_can_download_without_a_token(): void
    {
        Storage::fake('pdf');

        $signedBytes = '%PDF-1.7 signed output';
        $this->fakeRendererReturning($signedBytes, null, ['signature_appearance_image']);

        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.create']));

        $response = $this->post('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 source'),
            'original_name' => 'report.pdf',
            'digital_signature_id' => $this->seal(DigitalSignature::class, ['name' => '检测专用章'])->id,
        ]);

        $response->assertOk();

        // The desk downloads from this rather than the `blob:` URL: a browser
        // that hands downloads to its own download manager cannot act on a blob,
        // and the automatic download silently does nothing.
        $link = (string) $response->headers->get('X-Final-Download-Url');
        $this->assertNotSame('', $link);

        // Fetched the way the browser will: a plain GET, no bearer token.
        $downloaded = $this->get($link);
        $downloaded->assertOk();
        $this->assertSame($signedBytes, $downloaded->streamedContent());
    }

    public function test_the_operator_confirmed_report_number_overrides_the_cover_extraction(): void
    {
        Storage::fake('pdf');

        // What extraction produced in production: a whole labelled cover line
        // rather than the number. It reaches the ledger search and the report
        // recipient, so the operator's confirmation has to win.
        $this->fakeRendererReturning('%PDF-1.7 signed output', ['report_number' => '产品名称:LED 面板灯'], ['signature_appearance_image']);

        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.create']));

        $response = $this->post('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 source'),
            'original_name' => 'XDP2025120133 民爆 面板灯 委托检测报告.pdf',
            'report_number' => 'XDP2025120133',
            'digital_signature_id' => $this->seal(DigitalSignature::class, ['name' => '检测专用章'])->id,
        ]);

        $response->assertOk();
        $this->assertSame('XDP2025120133', rawurldecode((string) $response->headers->get('X-Cover-Report-Number')));

        $record = PdfFile::query()->sole();
        $this->assertSame('XDP2025120133', $record->cover_report_number);
        $this->assertSame('operator', $record->metadata['report_number_source']);

        // Extraction is still kept whole: a mismatch between the two is worth
        // being able to look at afterwards.
        $this->assertSame('产品名称:LED 面板灯', $record->metadata['cover_fields']['report_number']);
    }

    public function test_a_report_number_left_blank_is_recorded_as_absent_rather_than_empty(): void
    {
        Storage::fake('pdf');

        $this->fakeRendererReturning('%PDF-1.7 signed output', ['report_number' => null], ['signature_appearance_image']);

        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.create']));

        $this->post('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 source'),
            'original_name' => 'report.pdf',
            'report_number' => '   ',
            'digital_signature_id' => $this->seal(DigitalSignature::class, ['name' => '检测专用章'])->id,
        ])->assertOk();

        $record = PdfFile::query()->sole();
        $this->assertNull($record->cover_report_number);
        $this->assertSame('none', $record->metadata['report_number_source']);
    }

    public function test_signing_without_any_seal_selected_still_records_the_file_unsigned(): void
    {
        Storage::fake('pdf');

        // No seals: the Java round trip is skipped, so the ledger must record
        // the upload itself rather than pretend it was signed.
        $client = Mockery::mock(PdfRendererClient::class);
        $client->shouldNotReceive('processPdf');
        $this->app->instance(PdfRendererClient::class, $client);

        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.create']));

        $this->post('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 source'),
            'original_name' => 'report.pdf',
        ])->assertOk();

        $record = PdfFile::query()->sole();
        $this->assertFalse($record->metadata['signed']);
        $this->assertSame(hash('sha256', '%PDF-1.7 source'), $record->sha256_hash);
    }

    public function test_completion_log_carries_what_a_slow_report_needs_to_be_diagnosed(): void
    {
        Storage::fake('pdf');
        $this->fakeRendererReturning('%PDF-1.7 signed output');

        Log::spy();

        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.create']));

        $signature = $this->seal(DigitalSignature::class, ['name' => '检测专用章']);
        $perforation = $this->seal(PerforationStamp::class, ['name' => '骑缝章']);
        $functionStamp = $this->functionStamp('CMA');

        // A two-page document, so the logged page count is not a lucky default.
        $source = "%PDF-1.4\n1 0 obj<</Type/Pages/Count 2>>endobj\n%%EOF";

        $this->post('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', $source),
            'original_name' => 'report.pdf',
            'digital_signature_id' => $signature->id,
            'perforation_stamp_id' => $perforation->id,
            'function_stamp_ids' => [$functionStamp->id],
        ])->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                if ($message !== 'PDF 签章完成') {
                    return false;
                }

                // Signing cost is page count times document size; without all
                // three a "took forever" report cannot be explained afterwards.
                $this->assertSame(2, $context['page_count']);
                $this->assertGreaterThan(0, $context['input_bytes']);
                $this->assertGreaterThan(0, $context['output_bytes']);
                $this->assertArrayHasKey('duration_ms', $context);
                $this->assertArrayHasKey('sign', $context['phase_ms']);
                $this->assertSame('report.pdf', $context['file_name']);

                return true;
            })
            ->once();
    }

    public function test_page_count_is_null_rather_than_wrong_for_object_stream_pdfs(): void
    {
        Storage::fake('pdf');
        $this->fakeRendererReturning('%PDF-1.7 signed output');

        Log::spy();

        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.create']));

        $signature = $this->seal(DigitalSignature::class, ['name' => '检测专用章']);
        $perforation = $this->seal(PerforationStamp::class, ['name' => '骑缝章']);
        $functionStamp = $this->functionStamp('CMA');

        // Object streams hide the page tree from a byte scan; a guessed number
        // in the log would be worse than none.
        $source = "%PDF-1.5\n1 0 obj<</Type/ObjStm/N 4>>stream\n...\nendstream\n%%EOF";

        $this->post('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', $source),
            'digital_signature_id' => $signature->id,
            'perforation_stamp_id' => $perforation->id,
            'function_stamp_ids' => [$functionStamp->id],
        ])->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                if ($message !== 'PDF 签章完成') {
                    return false;
                }

                $this->assertNull($context['page_count']);

                return true;
            })
            ->once();
    }

    public function test_abandoned_working_directories_are_swept_but_recent_ones_are_kept(): void
    {
        Storage::fake('pdf');
        $this->fakeRendererReturning('%PDF-1.7 signed output');

        // storage_path() is not affected by Storage::fake, so this test touches
        // the real filesystem; keep the names unique and clean them up below.
        $workingRoot = storage_path('app/private/pdf/working');
        $stale = $workingRoot.'/stale-'.Str::uuid();
        $active = $workingRoot.'/active-'.Str::uuid();

        foreach ([$stale, $active] as $directory) {
            mkdir($directory, 0775, true);
            file_put_contents($directory.'/input.pdf', 'leftover');
        }

        // Older than the TTL: the process that owned it never came back.
        touch($stale, time() - 7200);
        config(['pdf_service.signing.working_dir_ttl_seconds' => 3600]);

        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.create']));

        $signature = $this->seal(DigitalSignature::class, ['name' => '检测专用章']);
        $perforation = $this->seal(PerforationStamp::class, ['name' => '骑缝章']);
        $functionStamp = $this->functionStamp('CMA');

        $this->post('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 source'),
            'digital_signature_id' => $signature->id,
            'perforation_stamp_id' => $perforation->id,
            'function_stamp_ids' => [$functionStamp->id],
        ])->assertOk();

        try {
            $this->assertDirectoryDoesNotExist($stale);
            // A slow job still running must never have its inputs deleted.
            $this->assertDirectoryExists($active);
        } finally {
            // The signing job removes its own directory; this test must remove
            // the one it deliberately kept, or it pollutes the next run.
            @unlink($active.'/input.pdf');
            @rmdir($active);
        }
    }

    public function test_photometric_mode_is_rejected_while_the_feature_is_off(): void
    {
        Storage::fake('pdf');
        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.create']));

        $this->postJson('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 source'),
            'remove_photometric_content' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.remove_photometric_content.0', 'photometric_removal_disabled');

        $this->assertSame(0, PdfFile::query()->count());
    }

    public function test_signing_is_unavailable_when_the_pdf_service_is_disabled(): void
    {
        config(['pdf_service.enabled' => false]);
        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.create']));

        $this->postJson('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 source'),
        ])->assertStatus(503);
    }

    public function test_signing_requires_permission(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['pdf_signing.read']));

        $this->postJson('/api/pdf/signing/process', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 source'),
        ])->assertForbidden();
    }

    /**
     * @param  array<string, mixed>|null  $coverFields
     * @param  list<string>  $expectedFiles  seal images this request should hand the Java service
     */
    private function fakeRendererReturning(
        string $signedBytes,
        ?array $coverFields = null,
        array $expectedFiles = ['signature_appearance_image', 'perforation_image', 'function_stamp_0'],
    ): void {
        $client = Mockery::mock(PdfRendererClient::class);
        $client->shouldReceive('processPdf')
            ->once()
            ->andReturnUsing(function (string $pdfPath, array $fields, array $files) use ($signedBytes, $coverFields, $expectedFiles): array {
                // Assert the contract the Java service expects.
                $this->assertSame('custom', $fields['mode']);
                $this->assertArrayNotHasKey('hash_algo', $fields);
                $this->assertArrayNotHasKey('tsa_enabled', $fields);
                $this->assertArrayNotHasKey('tsa_url', $fields);
                $this->assertSame(
                    count(array_filter($expectedFiles, fn (string $key): bool => str_starts_with($key, 'function_stamp_'))),
                    $fields['function_stamp_count'],
                );

                foreach ($expectedFiles as $key) {
                    $this->assertArrayHasKey($key, $files);
                }

                $outputPath = storage_path('app/private/pdf-renderer-test-'.Str::uuid().'.pdf');

                if (! is_dir(dirname($outputPath))) {
                    mkdir(dirname($outputPath), 0775, true);
                }

                file_put_contents($outputPath, $signedBytes);

                return ['pdf_path' => $outputPath, 'cover_fields' => $coverFields, 'response' => []];
            });

        $this->app->instance(PdfRendererClient::class, $client);
    }

    /**
     * @param  class-string<DigitalSignature|PerforationStamp>  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    private function seal(string $modelClass, array $attributes): DigitalSignature|PerforationStamp
    {
        $directory = $modelClass === DigitalSignature::class ? 'digital-signatures' : 'perforation-stamps';
        $path = $directory.'/'.Str::uuid()->toString().'.png';
        Storage::disk('pdf')->put($path, 'fake-png');

        return $modelClass::query()->create($attributes + [
            'appearance_image_path' => $path,
            'is_active' => true,
        ]);
    }

    private function functionStamp(string $name): HomepageFunctionStamp
    {
        $path = 'function-stamps/'.Str::uuid()->toString().'.png';
        Storage::disk('pdf')->put($path, 'fake-png');

        return HomepageFunctionStamp::query()->create([
            'name' => $name,
            'image_path' => $path,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_pdf_signing_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

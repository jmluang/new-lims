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
        Storage::disk('pdf')->assertExists($record->file_path);
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

    private function fakeRendererReturning(string $signedBytes, ?array $coverFields = null): void
    {
        $client = Mockery::mock(PdfRendererClient::class);
        $client->shouldReceive('processPdf')
            ->once()
            ->andReturnUsing(function (string $pdfPath, array $fields, array $files) use ($signedBytes, $coverFields): array {
                // Assert the contract the Java service expects.
                $this->assertSame('custom', $fields['mode']);
                $this->assertSame(1, $fields['function_stamp_count']);
                $this->assertArrayHasKey('signature_appearance_image', $files);
                $this->assertArrayHasKey('perforation_image', $files);
                $this->assertArrayHasKey('function_stamp_0', $files);

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

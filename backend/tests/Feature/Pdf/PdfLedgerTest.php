<?php

namespace Tests\Feature\Pdf;

use App\Models\PdfFile;
use App\Models\PdfVerificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PdfLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('pdf');
    }

    public function test_signed_files_can_be_searched_by_digest_and_downloaded(): void
    {
        Storage::disk('pdf')->put('signed/2026/08/report.pdf', '%PDF signed');

        $wanted = $this->ledgerRecord('ZST-1', 'a-report.pdf', 'signed/2026/08/report.pdf');
        $this->ledgerRecord('ZST-2', 'other.pdf');

        Sanctum::actingAs($this->userWithPermissions(['pdf_files.read', 'pdf_files.download']));

        $this->getJson('/api/pdf/files')->assertOk()->assertJsonCount(2, 'data');

        // Pasting a hash must find exactly its record.
        $this->getJson('/api/pdf/files?search='.$wanted->sha256_hash)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.file_id', 'ZST-1');

        $this->getJson("/api/pdf/files/{$wanted->id}/download")->assertOk();
    }

    public function test_download_is_not_found_when_the_stored_file_is_gone(): void
    {
        $record = $this->ledgerRecord('ZST-3', 'missing.pdf', 'signed/2026/08/missing.pdf');

        Sanctum::actingAs($this->userWithPermissions(['pdf_files.read', 'pdf_files.download']));

        $this->getJson("/api/pdf/files/{$record->id}/download")->assertNotFound();
    }

    public function test_verification_logs_can_be_filtered_by_outcome(): void
    {
        PdfVerificationLog::query()->create($this->logAttributes(true, PdfVerificationLog::SOURCE_ADMIN));
        PdfVerificationLog::query()->create($this->logAttributes(false, PdfVerificationLog::SOURCE_PUBLIC));

        Sanctum::actingAs($this->userWithPermissions(['pdf_verification_logs.read']));

        $this->getJson('/api/pdf/verification-logs')->assertOk()->assertJsonCount(2, 'data');

        // The UI's "all" option submits an empty string; it must not be read as
        // a false filter, which would hide every passing check.
        $this->getJson('/api/pdf/verification-logs?overall_valid=&verify_source=')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/pdf/verification-logs?overall_valid=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.verify_source', PdfVerificationLog::SOURCE_PUBLIC);

        $this->getJson('/api/pdf/verification-logs?overall_valid=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.verify_source', PdfVerificationLog::SOURCE_ADMIN);

        $this->getJson('/api/pdf/verification-logs?security_level=compromised')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.overall_valid', false);
    }

    public function test_verification_log_detail_returns_the_stored_payload(): void
    {
        $log = PdfVerificationLog::query()->create($this->logAttributes(false, PdfVerificationLog::SOURCE_PUBLIC) + [
            'verification_data' => ['overall_valid' => false, 'verification_message' => '验证失败: 未找到数据库记录'],
        ]);

        Sanctum::actingAs($this->userWithPermissions(['pdf_verification_logs.read']));

        $this->getJson("/api/pdf/verification-logs/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.verification_data.overall_valid', false)
            ->assertJsonPath('data.verification_data.verification_message', '验证失败: 未找到数据库记录');
    }

    public function test_signed_file_detail_exposes_metadata(): void
    {
        $record = $this->ledgerRecord('ZST-4', 'detail.pdf');
        $record->update(['metadata' => [
            'signed' => true,
            'digital_signature_id' => 7,
            'function_stamp_ids' => [1, 2],
            'cover_fields' => ['report_number' => 'ZS-2026-0009'],
        ]]);

        Sanctum::actingAs($this->userWithPermissions(['pdf_files.read']));

        $this->getJson("/api/pdf/files/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.metadata.digital_signature_id', 7)
            ->assertJsonPath('data.metadata.function_stamp_ids', [1, 2])
            ->assertJsonPath('data.cover_fields.report_number', 'ZS-2026-0009');
    }

    public function test_ledger_requires_permission(): void
    {
        Sanctum::actingAs($this->userWithPermissions([]));

        $this->getJson('/api/pdf/files')->assertForbidden();
        $this->getJson('/api/pdf/verification-logs')->assertForbidden();
    }

    private function ledgerRecord(string $fileId, string $fileName, ?string $filePath = null): PdfFile
    {
        return PdfFile::query()->create([
            'file_id' => $fileId,
            'file_name' => $fileName,
            'file_path' => $filePath,
            'sha256_hash' => hash('sha256', $fileId),
            'md5_hash' => hash('md5', $fileId),
            'file_size' => 1024,
            'signed_at' => now(),
            'created_by' => '张三',
            'metadata' => ['signed' => true],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function logAttributes(bool $valid, string $source): array
    {
        return [
            'file_name' => 'report.pdf',
            'file_size' => 1024,
            'primary_hash' => hash('sha256', $source),
            'overall_valid' => $valid,
            'security_level' => $valid ? 'high' : 'compromised',
            'verification_message' => $valid ? '验证通过' : '验证失败: 未找到数据库记录',
            'verify_source' => $source,
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_pdf_ledger_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

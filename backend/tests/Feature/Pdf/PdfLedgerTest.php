<?php

namespace Tests\Feature\Pdf;

use App\Models\PdfFile;
use App\Models\PdfVerificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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

    public function test_the_temporary_link_downloads_without_a_token_but_only_while_its_signature_holds(): void
    {
        Storage::disk('pdf')->put('signed/2026/08/report.pdf', '%PDF signed');
        $record = $this->ledgerRecord('ZST-9', 'a-report.pdf', 'signed/2026/08/report.pdf');

        // The signing desk hands this to the browser because a `blob:` URL is
        // useless to a browser with its own download manager. No bearer token
        // rides on a plain <a href>, so the signature is the authorisation.
        $url = URL::temporarySignedRoute('pdf.files.temporary-download', now()->addMinutes(30), ['pdfFile' => $record->id]);

        $response = $this->get($url);
        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        // Unsigned, tampered and expired links must all be refused, or the
        // ledger becomes a public download site keyed by row id.
        $this->get("/api/pdf/files/{$record->id}/temporary-download")->assertForbidden();
        $this->get($url.'x')->assertForbidden();

        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_the_temporary_link_delivers_the_signed_file_name(): void
    {
        Storage::disk('pdf')->put('signed/2026/08/report.pdf', '%PDF signed');
        $record = $this->ledgerRecord('ZST-10', 'XDP2025120133 民爆 面板灯.pdf', 'signed/2026/08/report.pdf');

        $url = URL::temporarySignedRoute('pdf.files.temporary-download', now()->addMinutes(30), ['pdfFile' => $record->id]);

        // The operator must get the same name whichever of the two paths the
        // browser took, so this shares the signing response's convention.
        $this->assertSame('XDP2025120133 民爆 面板灯-正本.pdf', $record->signedDownloadName());
        $this->assertStringContainsString(
            'filename*=',
            (string) $this->get($url)->headers->get('content-disposition'),
        );
    }

    public function test_download_is_not_found_when_the_stored_file_is_gone(): void
    {
        $record = $this->ledgerRecord('ZST-3', 'missing.pdf', 'signed/2026/08/missing.pdf');

        Sanctum::actingAs($this->userWithPermissions(['pdf_files.read', 'pdf_files.download']));

        $this->getJson("/api/pdf/files/{$record->id}/download")->assertNotFound();
    }

    public function test_a_standalone_file_can_be_deleted_with_an_audit_record(): void
    {
        Storage::disk('pdf')->put('signed/delete.pdf', '%PDF signed');
        $record = $this->ledgerRecord('LEDGER-DELETE', 'delete.pdf', 'signed/delete.pdf');
        $other = $this->ledgerRecord('LEDGER-KEEP', 'keep.pdf');
        Sanctum::actingAs($this->userWithPermissions(['pdf_files.read', 'pdf_files.delete']));

        $this->getJson('/api/permissions/effective')->assertOk()->assertJsonPath('data.resources.pdf_files.actions.delete', true);

        $this->deleteJson("/api/pdf/files/{$record->id}")->assertOk();

        $this->assertDatabaseMissing('pdf_files', ['id' => $record->id]);
        $this->assertDatabaseHas('pdf_files', ['id' => $other->id]);
        Storage::disk('pdf')->assertMissing('signed/delete.pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'pdf_files.deleted', 'subject_id' => (string) $record->id]);
        $this->getJson('/api/pdf/files')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/pdf/files/{$record->id}")->assertNotFound();
    }

    public function test_read_permission_does_not_allow_deleting_a_file(): void
    {
        Storage::disk('pdf')->put('signed/keep.pdf', '%PDF signed');
        $record = $this->ledgerRecord('LEDGER-DENIED', 'keep.pdf', 'signed/keep.pdf');
        Sanctum::actingAs($this->userWithPermissions(['pdf_files.read']));

        $this->deleteJson("/api/pdf/files/{$record->id}")->assertForbidden();

        $this->assertDatabaseHas('pdf_files', ['id' => $record->id]);
        Storage::disk('pdf')->assertExists('signed/keep.pdf');
    }

    public function test_workflow_revisions_cannot_be_deleted_from_the_ledger(): void
    {
        $record = $this->ledgerRecord('LEDGER-REVISION', 'revision.pdf');
        $record->update(['revision_uuid' => (string) str()->uuid()]);
        Sanctum::actingAs($this->userWithPermissions(['pdf_files.read', 'pdf_files.delete']));

        $this->getJson("/api/pdf/files/{$record->id}")->assertJsonPath('data.can_delete', false);
        $this->deleteJson("/api/pdf/files/{$record->id}")->assertConflict();

        $this->assertDatabaseHas('pdf_files', ['id' => $record->id]);
    }

    public function test_deleting_a_record_without_stored_bytes_succeeds(): void
    {
        $record = $this->ledgerRecord('LEDGER-MISSING-BYTES', 'missing.pdf', 'signed/missing.pdf');
        Sanctum::actingAs($this->userWithPermissions(['pdf_files.delete']));

        $this->deleteJson("/api/pdf/files/{$record->id}")->assertOk();

        $this->assertDatabaseMissing('pdf_files', ['id' => $record->id]);
    }

    public function test_deleting_a_record_preserves_bytes_used_by_another_record(): void
    {
        Storage::disk('pdf')->put('signed/shared.pdf', '%PDF signed');
        $record = $this->ledgerRecord('LEDGER-SHARED-DELETE', 'delete.pdf', 'signed/shared.pdf');
        $other = $this->ledgerRecord('LEDGER-SHARED-KEEP', 'keep.pdf', 'signed/shared.pdf');
        Sanctum::actingAs($this->userWithPermissions(['pdf_files.delete']));

        $this->deleteJson("/api/pdf/files/{$record->id}")->assertOk();

        $this->assertDatabaseMissing('pdf_files', ['id' => $record->id]);
        $this->assertDatabaseHas('pdf_files', ['id' => $other->id]);
        Storage::disk('pdf')->assertExists('signed/shared.pdf');
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

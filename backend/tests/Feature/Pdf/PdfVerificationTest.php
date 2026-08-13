<?php

namespace Tests\Feature\Pdf;

use App\Models\PdfFile;
use App\Models\PdfVerificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PdfVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_matching_digests_verify_and_are_logged(): void
    {
        $record = $this->ledgerRecord();
        $user = $this->userWithPermissions(['pdf_verification.create']);

        Sanctum::actingAs($user);

        $this->postJson('/api/pdf/verification/verify', [
            'file_name' => 'report.pdf',
            'file_size' => $record->file_size,
            'current_digests' => [
                'primary_hash' => $record->sha256_hash,
                'md5_hash' => $record->md5_hash,
                'file_size' => $record->file_size,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.overall_valid', true)
            ->assertJsonPath('data.security_level', 'high')
            ->assertJsonPath('data.verification_message', '验证通过')
            ->assertJsonPath('data.verification_details.database_verification.found', true)
            ->assertJsonPath('data.cover_report_number', 'ZS-2026-0001');

        $log = PdfVerificationLog::query()->sole();
        $this->assertTrue($log->overall_valid);
        $this->assertSame(PdfVerificationLog::SOURCE_ADMIN, $log->verify_source);
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_edited_file_fails_verification(): void
    {
        $record = $this->ledgerRecord();
        $user = $this->userWithPermissions(['pdf_verification.create']);

        Sanctum::actingAs($user);

        // Same byte count, different content: the classic silent edit.
        $this->postJson('/api/pdf/verification/verify', [
            'file_name' => 'report.pdf',
            'file_size' => $record->file_size,
            'current_digests' => [
                'primary_hash' => str_repeat('a', 64),
                'md5_hash' => str_repeat('b', 32),
                'file_size' => $record->file_size,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.overall_valid', false)
            ->assertJsonPath('data.security_level', 'compromised')
            ->assertJsonPath('data.verification_details.database_verification.found', false);

        $this->assertFalse(PdfVerificationLog::query()->sole()->overall_valid);
    }

    public function test_md5_collision_is_flagged_as_a_warning(): void
    {
        $record = $this->ledgerRecord();
        $user = $this->userWithPermissions(['pdf_verification.create']);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/pdf/verification/verify', [
            'file_name' => 'report.pdf',
            'file_size' => $record->file_size,
            'current_digests' => [
                // MD5 still matches while SHA-256 does not — only possible via a
                // collision, so the result must warn rather than pass.
                'primary_hash' => str_repeat('c', 64),
                'md5_hash' => $record->md5_hash,
                'file_size' => $record->file_size,
            ],
        ])->assertOk();

        $response->assertJsonPath('data.overall_valid', false);
        $this->assertContains(
            'MD5匹配但SHA256不匹配，可能遭受碰撞攻击！',
            $response->json('data.verification_details.warnings'),
        );
    }

    public function test_malformed_digest_is_rejected(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['pdf_verification.create']));

        $this->postJson('/api/pdf/verification/verify', [
            'file_name' => 'report.pdf',
            'file_size' => 1024,
            'current_digests' => ['primary_hash' => 'not-a-hash', 'file_size' => 1024],
        ])->assertStatus(422);
    }

    public function test_verification_requires_permission(): void
    {
        Sanctum::actingAs($this->userWithPermissions([]));

        $this->postJson('/api/pdf/verification/verify', [
            'file_name' => 'report.pdf',
            'file_size' => 1024,
            'current_digests' => ['primary_hash' => str_repeat('a', 64), 'file_size' => 1024],
        ])->assertForbidden();
    }

    public function test_public_endpoint_verifies_an_uploaded_file_without_login(): void
    {
        $contents = '%PDF-1.4 signed report bytes';
        PdfFile::query()->create([
            'file_id' => 'ZST-PUBLIC-1',
            'file_name' => 'report.pdf',
            'sha256_hash' => hash('sha256', $contents),
            'md5_hash' => hash('md5', $contents),
            'file_size' => strlen($contents),
            'signed_at' => now(),
            'created_by' => '张三',
            'cover_report_number' => 'ZS-2026-0002',
            'metadata' => ['cover_fields' => ['report_number' => 'ZS-2026-0002']],
        ]);

        $response = $this->post('/api/public/pdf/verify', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', $contents),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.overall_valid', true)
            ->assertJsonPath('data.verification_details.database_verification.record.cover_report_number', 'ZS-2026-0002');

        // The public response must not disclose who signed it internally.
        $this->assertNull($response->json('data.verification_details.database_verification.record.created_by'));
        $this->assertSame(PdfVerificationLog::SOURCE_PUBLIC, PdfVerificationLog::query()->sole()->verify_source);
    }

    public function test_public_endpoint_can_be_disabled(): void
    {
        config(['pdf_service.public_verification.enabled' => false]);

        $this->post('/api/public/pdf/verify', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', 'x'),
        ], ['Accept' => 'application/json'])->assertNotFound();
    }

    private function ledgerRecord(): PdfFile
    {
        return PdfFile::query()->create([
            'file_id' => 'ZST-0001',
            'file_name' => 'report.pdf',
            'sha256_hash' => hash('sha256', 'signed-report'),
            'md5_hash' => hash('md5', 'signed-report'),
            'file_size' => 204800,
            'signed_at' => now(),
            'created_by' => '李四',
            'cover_report_number' => 'ZS-2026-0001',
            'metadata' => ['cover_fields' => ['report_number' => 'ZS-2026-0001']],
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_pdf_verify_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

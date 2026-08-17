<?php

namespace Tests\Feature\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningPolicyVersion;
use App\Models\PdfSourceUpload;
use App\Models\User;
use App\Services\Pdf\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PdfDocumentDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_list_shows_the_stage_and_who_still_owes_a_signature(): void
    {
        $actor = $this->actor(['pdf.document.read']);
        $this->document($actor, 'RPT-1');

        $response = $this->getJson('/api/pdf/documents')->assertOk();

        $this->assertSame('RPT-1', $response->json('data.0.report_number'));
        $this->assertSame('finalized_awaiting_workflow', $response->json('data.0.stage'));
        $this->assertFalse($response->json('data.0.has_running_work'));
        $this->assertTrue($response->json('data.0.is_owner'));
        $this->assertSame([], $response->json('data.0.signers'));
        $this->assertSame('finalized_unsigned', $response->json('data.0.revisions.0.revision_role'));
    }

    public function test_document_list_can_be_searched_by_report_number(): void
    {
        $actor = $this->actor(['pdf.document.read']);
        $this->document($actor, 'XDP-AAA');
        $this->document($actor, 'XDP-BBB');

        $response = $this->getJson('/api/pdf/documents?search=BBB')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('XDP-BBB', $response->json('data.0.report_number'));
    }

    public function test_a_single_document_can_be_fetched_for_resuming_its_plan(): void
    {
        $actor = $this->actor(['pdf.document.read']);
        $document = $this->document($actor, 'RESUME-1');

        $this->getJson("/api/pdf/documents/{$document->document_uuid}")
            ->assertOk()
            ->assertJsonPath('data.report_number', 'RESUME-1')
            // The planning workspace reloads this revision instead of a new upload.
            ->assertJsonPath('data.revisions.0.revision_role', 'finalized_unsigned');
    }

    public function test_a_draft_report_number_can_be_corrected(): void
    {
        $actor = $this->actor(['pdf.document.read', 'pdf.document.update']);
        $document = $this->document($actor, 'OLD-1');

        // TrimStrings has already trimmed the request, so the stored display value
        // is the trimmed input and the normalized form is upper-cased.
        $this->patchJson("/api/pdf/documents/{$document->document_uuid}", ['report_number' => '  new-1 '])
            ->assertOk()
            ->assertJsonPath('data.report_number', 'new-1');

        $this->assertSame('NEW-1', $document->refresh()->normalized_report_number);
    }

    public function test_renaming_onto_a_taken_report_number_is_rejected(): void
    {
        $actor = $this->actor(['pdf.document.update']);
        $this->document($actor, 'TAKEN-1');
        $second = $this->document($actor, 'FREE-1');

        $this->patchJson("/api/pdf/documents/{$second->document_uuid}", ['report_number' => 'TAKEN-1'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'PDF_REPORT_NUMBER_ALREADY_REGISTERED');
    }

    public function test_deleting_a_draft_frees_its_report_number_and_bytes(): void
    {
        Storage::fake('pdf');
        $actor = $this->actor(['pdf.document.read', 'pdf.document.delete']);
        $document = $this->document($actor, 'GONE-1', storeBytes: true);

        $this->deleteJson("/api/pdf/documents/{$document->document_uuid}")
            ->assertOk()
            ->assertJsonPath('data.report_number', 'GONE-1')
            ->assertJsonPath('data.deleted_files', 2);

        $this->assertSame(0, PdfDocument::query()->count());
        $this->assertSame(0, PdfSourceUpload::query()->count());
        $this->assertSame(0, PdfFile::query()->count());
        $this->assertCount(0, Storage::disk('pdf')->allFiles('workflow'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'pdf.document.deleted']);
    }

    public function test_a_signed_document_can_be_neither_renamed_nor_deleted(): void
    {
        $actor = $this->actor(['pdf.document.update', 'pdf.document.delete']);
        $document = $this->document($actor, 'SIGNED-1');
        PdfFile::query()->where('document_id', $document->id)->update(['revision_role' => 'handwritten_signature']);

        $this->patchJson("/api/pdf/documents/{$document->document_uuid}", ['report_number' => 'RENAMED-1'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'PDF_DOCUMENT_ALREADY_SIGNED');
        $this->deleteJson("/api/pdf/documents/{$document->document_uuid}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'PDF_DOCUMENT_ALREADY_SIGNED');
        $this->assertSame(1, PdfDocument::query()->count());
    }

    public function test_a_cancelled_document_can_still_be_corrected_or_removed(): void
    {
        $actor = $this->actor(['pdf.document.update']);
        $document = $this->document($actor, 'CANCELLED-1');
        // Cancelling a workflow leaves the document cancelled with nothing signed.
        $document->update(['status' => 'cancelled']);

        $this->patchJson("/api/pdf/documents/{$document->document_uuid}", ['report_number' => 'CANCELLED-2'])
            ->assertOk()
            ->assertJsonPath('data.report_number', 'CANCELLED-2');
    }

    public function test_a_prepared_revision_is_not_mistaken_for_a_signature(): void
    {
        $actor = $this->actor(['pdf.document.update']);
        $document = $this->document($actor, 'PREPARED-1');
        // prepare_fields adds the empty signature fields; nothing is signed yet.
        PdfFile::query()->create([
            'document_id' => $document->id,
            'revision_uuid' => (string) Str::uuid(),
            'revision_number' => 2,
            'revision_role' => 'prepared',
            'revision_created_at' => now(),
            'integrity_state' => 'ready',
            'file_id' => 'REV-prepared-1',
            'file_name' => 'report.pdf',
            'file_path' => 'workflow/revisions/prepared-1/document.pdf',
            'file_size' => 13,
            'sha256_hash' => hash('sha256', 'prepared-1'),
            'md5_hash' => md5('prepared-1'),
            'signed_at' => now(),
            'created_by' => $actor->name,
            'created_by_id' => $actor->id,
        ]);

        $this->patchJson("/api/pdf/documents/{$document->document_uuid}", ['report_number' => 'PREPARED-2'])
            ->assertOk();
    }

    public function test_a_workflow_refuses_an_assignee_who_cannot_sign(): void
    {
        $actor = $this->actor(['pdf.workflow.create']);
        $document = $this->document($actor, 'ASSIGNEE-1');
        $revision = PdfFile::query()->where('document_id', $document->id)->sole();
        Permission::findOrCreate('pdf.request.sign_assigned', 'web');
        $canSign = User::factory()->create();
        $canSign->givePermissionTo('pdf.request.sign_assigned');
        $cannotSign = User::factory()->create();

        $this->withHeader('Idempotency-Key', 'assignee-guard-1')
            ->postJson('/api/pdf/signing-workflows', [
                'planning_revision_uuid' => $revision->revision_uuid,
                'signing_policy_version_uuid' => $this->policy()->version_uuid,
                'assignments' => [
                    'inspector' => $canSign->id,
                    'reviewer' => $cannotSign->id,
                    'issuer' => $canSign->id,
                ],
                'placements' => [
                    ['semantic_role' => 'inspector', 'page_index' => 0, 'normalized_rect' => $this->rect()],
                    ['semantic_role' => 'reviewer', 'page_index' => 0, 'normalized_rect' => $this->rect()],
                    ['semantic_role' => 'issuer', 'page_index' => 0, 'normalized_rect' => $this->rect()],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PDF_ASSIGNEE_CANNOT_SIGN');
    }

    /** @return array{x: string, y: string, width: string, height: string} */
    private function rect(): array
    {
        return ['x' => '0.100000', 'y' => '0.700000', 'width' => '0.160000', 'height' => '0.055000'];
    }

    public function test_a_published_document_is_protected(): void
    {
        $actor = $this->actor(['pdf.document.delete']);
        $document = $this->document($actor, 'PUB-1');
        $document->update([
            'status' => 'published',
            'published_revision_id' => PdfFile::query()->where('document_id', $document->id)->value('id'),
            'publication_version' => 1,
        ]);

        $this->deleteJson("/api/pdf/documents/{$document->document_uuid}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'PDF_DOCUMENT_NOT_A_DRAFT');
    }

    public function test_a_document_under_evidence_hold_is_protected(): void
    {
        $actor = $this->actor(['pdf.document.delete']);
        $document = $this->document($actor, 'HOLD-1');
        $document->update(['evidence_hold_state' => 'active', 'evidence_hold_mask' => 1]);

        $this->deleteJson("/api/pdf/documents/{$document->document_uuid}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'PDF_DOCUMENT_UNDER_HOLD');
    }

    public function test_a_document_with_running_work_is_protected(): void
    {
        $actor = $this->actor(['pdf.document.delete']);
        $document = $this->document($actor, 'BUSY-1');
        PdfSigningOperation::query()->create([
            'operation_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'busy-1',
            'idempotency_scope_key' => 'busy-scope-1',
            'scope_type' => 'document',
            'actor_user_id' => $actor->id,
            'document_id' => $document->id,
            'action' => 'unsigned_finalize',
            'input_fingerprint' => str_repeat('a', 64),
            'operation_input_manifest_hash' => str_repeat('b', 64),
            'expected_source_sha256' => str_repeat('c', 64),
            'result_revision_uuid' => (string) Str::uuid(),
            'state' => 'processing',
            'stage' => 'staging',
            'audit_context' => [],
            'audit_context_hash' => str_repeat('d', 64),
        ]);

        $this->deleteJson("/api/pdf/documents/{$document->document_uuid}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'PDF_DOCUMENT_HAS_RUNNING_WORK');
    }

    public function test_another_user_cannot_delete_someone_elses_draft(): void
    {
        $owner = $this->actor(['pdf.document.delete']);
        $document = $this->document($owner, 'MINE-1');
        $other = $this->actor(['pdf.document.delete']);

        $this->deleteJson("/api/pdf/documents/{$document->document_uuid}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'PDF_DOCUMENT_NOT_OWNED');
        $this->assertNotSame($owner->id, $other->id);
    }

    public function test_the_list_requires_its_own_permission(): void
    {
        $this->actor([]);

        $this->getJson('/api/pdf/documents')->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }

        Sanctum::actingAs($user->refresh());

        return $user;
    }

    private function document(User $actor, string $reportNumber, bool $storeBytes = false): PdfDocument
    {
        config(['pdf_service.organization_scope' => 'default']);
        $document = PdfDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'document_public_id' => Str::random(48),
            'organization_scope' => 'default',
            'authoritative_report_number' => $reportNumber,
            'normalized_report_number' => strtoupper($reportNumber),
            'status' => 'draft',
            'created_by_id' => $actor->id,
        ]);
        $sourceUuid = (string) Str::uuid();
        $revisionUuid = (string) Str::uuid();
        $sourcePath = "workflow/sources/{$sourceUuid}.pdf";
        $revisionPath = "workflow/revisions/{$revisionUuid}/document.pdf";

        if ($storeBytes) {
            Storage::disk('pdf')->put($sourcePath, '%PDF source');
            Storage::disk('pdf')->put($revisionPath, '%PDF revision');
        }

        PdfSourceUpload::query()->create([
            'source_uuid' => $sourceUuid,
            'document_id' => $document->id,
            'stored_path' => $sourcePath,
            'sha256' => hash('sha256', $reportNumber.'source'),
            'file_size' => 11,
            'page_count' => 1,
            'inspection_manifest' => [],
            'inspection_manifest_hash' => str_repeat('e', 64),
            'created_by_id' => $actor->id,
            'expires_at' => now()->addDay(),
            'status' => 'bound',
        ]);
        PdfFile::query()->create([
            'document_id' => $document->id,
            'revision_uuid' => $revisionUuid,
            'revision_number' => 1,
            'revision_role' => 'finalized_unsigned',
            'revision_created_at' => now(),
            'integrity_state' => 'ready',
            'file_id' => 'REV-'.$revisionUuid,
            'file_name' => 'report.pdf',
            'file_path' => $revisionPath,
            'file_size' => 13,
            'sha256_hash' => hash('sha256', $reportNumber.'revision'),
            'md5_hash' => md5($reportNumber.'revision'),
            'signed_at' => now(),
            'created_by' => $actor->name,
            'created_by_id' => $actor->id,
        ]);

        return $document->refresh();
    }

    private function policy(): PdfSigningPolicyVersion
    {
        $manifest = ['profile' => 'B-T', 'version' => 1];

        return PdfSigningPolicyVersion::query()->create([
            'version_uuid' => (string) Str::uuid(),
            'policy_hash' => hash('sha256', CanonicalJson::encode($manifest)),
            'immutable_at' => now(),
            'pades_profile' => 'B-T',
            'digest_algorithm_oid' => '2.16.840.1.101.3.4.2.1',
            'signature_algorithm_oid' => '1.2.840.113549.1.1.11',
            'organization_certificate_fingerprints' => [str_repeat('b', 64)],
            'signing_material_version' => 'test-v1',
            'key_locator' => 'test',
            'tsa_url_set' => ['http://tsa.invalid'],
            'tsa_policy_oid' => '1.2.3.4',
            'tsa_timeout_seconds' => 10,
            'trust_bundle_hash' => str_repeat('c', 64),
            'revocation_policy' => ['mode' => 'required'],
            'reserved_size' => 32768,
            'pre_private_key_retry_backoff_seconds' => [2, 5],
            'pre_private_key_retryable_error_codes' => ['DB_UNAVAILABLE'],
            'java_execution_registration_timeout_seconds' => 15,
            'java_execution_timeout_seconds' => 90,
            'java_status_poll_policy' => ['initial' => 2, 'max' => 10],
            'java_result_min_bytes_per_second' => 1048576,
            'java_result_read_timeout_seconds' => 60,
            'generated_revision_max_bytes' => 33554432,
            'max_signature_increment_bytes' => 2097152,
            'policy_manifest' => $manifest,
            'config_bundle_hash' => hash('sha256', CanonicalJson::encode($manifest)),
        ]);
    }
}

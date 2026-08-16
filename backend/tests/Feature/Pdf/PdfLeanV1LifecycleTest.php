<?php

namespace Tests\Feature\Pdf;

use App\Jobs\ResumePdfOperationFromJavaResult;
use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfJavaSigningExecution;
use App\Models\PdfOperationOutbox;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningAct;
use App\Models\PdfSigningField;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningPolicyVersion;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningSlot;
use App\Models\PdfSigningWorkflow;
use App\Models\User;
use App\Services\Pdf\AuthorizeJavaResultRetirementService;
use App\Services\Pdf\CanonicalJson;
use App\Services\Pdf\PdfAppearanceRetentionService;
use App\Services\Pdf\PdfDocumentEvidenceHoldService;
use App\Services\Pdf\PdfImmutableFileStore;
use App\Services\Pdf\PdfOperationOutboxDispatcher;
use App\Services\Pdf\PdfRendererClient;
use App\Services\Pdf\PdfRevisionIntegrityService;
use App\Services\Pdf\PdfRevisionService;
use App\Services\Pdf\PdfWorkflowService;
use App\Services\Pdf\ResolvePdfSigningManualReviewService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PdfLeanV1LifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_workflow_activates_existing_deferred_homepage_field_without_rewriting_pdf(): void
    {
        Storage::fake('pdf');
        $actor = User::factory()->create();
        $sealOperator = User::factory()->create();
        [$document, $published] = $this->publishedDocument($actor);
        Storage::disk('pdf')->put($published->file_path, '%PDF-1.7 published');
        $workflow = PdfSigningWorkflow::query()->create([
            'workflow_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'workflow_generation' => 1,
            'base_revision_id' => $published->id,
            'planning_revision_id' => $published->id,
            'prepared_revision_id' => $published->id,
            'current_revision_id' => $published->id,
            'expected_publication_version' => 0,
            'field_plan_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'created_by_id' => $actor->id,
        ]);
        $act = PdfSigningAct::query()->create([
            'logical_act_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'plan_generation' => 1,
            'semantic_role' => 'homepage_seal',
            'pdf_signature_role' => 'approval',
            'sequence' => 4,
            'field_name' => 'lims_homepage_seal_g1',
            'status' => 'deferred',
        ]);
        $sourceField = PdfSigningField::query()->create([
            'field_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'signing_act_id' => $act->id,
            'field_name' => 'lims_homepage_seal_g1',
            'field_type' => 'homepage_seal',
            'activation_mode' => 'deferred',
            'binding_mode' => 'created_before_first_signature',
            'lock_policy' => 'include_self_only',
            'prepared_revision_id' => $published->id,
            'prepared_object_ref' => '20 0 R',
            'status' => 'prepared',
        ]);
        PdfSigningSlot::query()->create([
            'slot_uuid' => (string) Str::uuid(),
            'field_id' => $sourceField->id,
            'page_index' => 0,
            'widget_index' => 0,
            'placement_type' => 'homepage_seal',
            'normalized_rect' => ['x' => '0.750000', 'y' => '0.080000', 'width' => '0.160000', 'height' => '0.160000'],
            'geometry_hash' => str_repeat('b', 64),
            'prepared_widget_object_ref' => '21 0 R',
            'prepared_appearance_object_refs' => ['22 0 R'],
            'status' => 'prepared',
        ]);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSignatureBytes')->once()->andReturn([
            'sha256' => $published->sha256_hash,
            'encrypted' => false,
            'signatureCount' => 3,
            'docMdpPermission' => 2,
            'fields' => [[
                'fieldName' => $sourceField->field_name,
                'signed' => false,
                'selfOnlyLock' => true,
                'widgetCount' => 1,
                'objectRef' => '20 0 R',
                'widgets' => [[
                    'widgetIndex' => 0,
                    'pageIndex' => 0,
                    'normalizedRectangle' => [
                        'x' => '0.750000',
                        'y' => '0.080000',
                        'width' => '0.160000',
                        'height' => '0.160000',
                    ],
                    'objectRef' => '21 0 R',
                    'appearanceObjectRefs' => ['22 0 R'],
                ]],
            ]],
        ]);
        $this->app->instance(PdfRendererClient::class, $renderer);

        $activated = app(PdfWorkflowService::class)->activateHomepageSeal(
            $workflow,
            $sealOperator->id,
            $this->policy(),
            $actor,
            'bind-homepage-seal-test-0001',
            ['request_id' => (string) Str::uuid()],
        );

        $this->assertSame('ready', $activated->status);
        $this->assertSame($published->id, $activated->current_revision_id);
        $this->assertSame($published->id, $activated->prepared_revision_id);
        $this->assertSame('homepage_seal', $activated->requests->sole()->request_type);
        $this->assertSame('available', $activated->requests->sole()->status);
        $this->assertSame('rebound_existing', $activated->fields->sole()->binding_mode);
        $this->assertSame($sourceField->id, $activated->fields->sole()->source_field_id);
        $this->assertSame($activated->id, $document->refresh()->active_workflow_id);
        $this->assertSame(1, PdfFile::query()->count());
        $this->assertDatabaseHas('pdf_signing_operations', [
            'action' => 'bind_deferred_field',
            'state' => 'completed',
            'result_revision_uuid' => null,
        ]);

        $replayed = app(PdfWorkflowService::class)->activateHomepageSeal(
            $workflow,
            $sealOperator->id,
            PdfSigningPolicyVersion::query()->sole(),
            $actor,
            'bind-homepage-seal-test-0001',
            ['request_id' => (string) Str::uuid()],
        );

        $this->assertSame($activated->id, $replayed->id);
        $this->assertSame(1, PdfSigningOperation::query()->where('action', 'bind_deferred_field')->count());
    }

    public function test_predecessor_request_cannot_cross_workflow_boundary(): void
    {
        $actor = User::factory()->create();
        [$document, $published] = $this->publishedDocument($actor);
        $policy = $this->policy();
        $firstWorkflow = PdfSigningWorkflow::query()->create([
            'workflow_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'workflow_generation' => 1,
            'current_revision_id' => $published->id,
            'status' => 'signing',
            'created_by_id' => $actor->id,
        ]);
        $secondWorkflow = PdfSigningWorkflow::query()->create([
            'workflow_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'workflow_generation' => 2,
            'current_revision_id' => $published->id,
            'status' => 'draft',
            'created_by_id' => $actor->id,
        ]);
        $firstAct = PdfSigningAct::query()->create([
            'logical_act_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'plan_generation' => 1,
            'semantic_role' => 'inspector',
            'pdf_signature_role' => 'certification_p2',
            'sequence' => 1,
            'field_name' => 'inspector_g1',
            'status' => 'planned',
        ]);
        $secondAct = PdfSigningAct::query()->create([
            'logical_act_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'plan_generation' => 2,
            'semantic_role' => 'reviewer',
            'pdf_signature_role' => 'approval',
            'sequence' => 2,
            'field_name' => 'reviewer_g2',
            'status' => 'planned',
        ]);
        $predecessor = PdfSigningRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'workflow_id' => $firstWorkflow->id,
            'signing_act_id' => $firstAct->id,
            'sequence' => 1,
            'request_type' => 'handwritten',
            'assigned_user_id' => $actor->id,
            'signing_policy_version_id' => $policy->id,
            'status' => 'available',
        ]);

        $this->expectException(QueryException::class);

        PdfSigningRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'workflow_id' => $secondWorkflow->id,
            'signing_act_id' => $secondAct->id,
            'sequence' => 2,
            'predecessor_request_id' => $predecessor->id,
            'request_type' => 'handwritten',
            'assigned_user_id' => $actor->id,
            'signing_policy_version_id' => $policy->id,
            'status' => 'pending',
        ]);
    }

    public function test_assigned_user_can_reject_available_request_without_changing_published_pointer(): void
    {
        $user = $this->userWithPermissions(['pdf.request.reject']);
        [$document, $published] = $this->publishedDocument($user);
        $workflow = PdfSigningWorkflow::query()->create([
            'workflow_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'workflow_generation' => 1,
            'current_revision_id' => $published->id,
            'status' => 'ready',
            'created_by_id' => $user->id,
        ]);
        $document->update(['active_workflow_id' => $workflow->id]);
        $act = PdfSigningAct::query()->create([
            'logical_act_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'plan_generation' => 1,
            'semantic_role' => 'reviewer',
            'pdf_signature_role' => 'approval',
            'sequence' => 2,
            'field_name' => 'reviewer',
            'status' => 'planned',
        ]);
        $request = PdfSigningRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'signing_act_id' => $act->id,
            'sequence' => 2,
            'request_type' => 'handwritten',
            'assigned_user_id' => $user->id,
            'signing_policy_version_id' => $this->policy()->id,
            'status' => 'available',
            'expected_source_revision_id' => $published->id,
            'expected_source_sha256' => $published->sha256_hash,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/pdf/signing-requests/{$request->request_uuid}/reject", [
            'reason_code' => 'CONTENT_REQUIRES_CORRECTION',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $document->refresh();
        $this->assertSame($published->id, $document->published_revision_id);
        $this->assertNull($document->active_workflow_id);
        $this->assertSame('published', $document->status);
        $this->assertSame('rejected', $workflow->refresh()->status);
        $this->assertSame('CONTENT_REQUIRES_CORRECTION', $request->refresh()->rejection_reason_code);
        $this->assertSame($user->id, $request->rejected_by_id);
    }

    public function test_public_revision_api_separates_registration_from_uploaded_byte_verification(): void
    {
        config(['pdf_service.enabled' => true]);
        $actor = User::factory()->create();
        [$document, $published] = $this->publishedDocument($actor, '%PDF-1.7 exact published bytes');
        DB::table('pdf_document_publication_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'revision_id' => $published->id,
            'event_type' => 'published',
            'occurred_at' => now(),
            'audit_context_hash' => str_repeat('c', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('verifySignaturePdf')->once()->andReturn([
            'documentCurrentState' => 'valid',
            'docMdpPermission' => 2,
            'signatures' => [[], [], []],
        ]);
        $this->app->instance(PdfRendererClient::class, $renderer);

        $this->getJson("/api/public/pdf/revisions/{$published->revision_uuid}")
            ->assertOk()
            ->assertJsonPath('data.registration_state', 'registered');
        $this->post("/api/public/pdf/revisions/{$published->revision_uuid}/verify", [
            'pdf_file' => UploadedFile::fake()->createWithContent('published.pdf', '%PDF-1.7 exact published bytes'),
        ])->assertOk()
            ->assertJsonPath('data.registered_bytes_match', true)
            ->assertJsonPath('data.verification_state', 'valid');
        $this->getJson("/api/public/pdf/documents/{$document->document_public_id}")
            ->assertOk()
            ->assertJsonPath('data.current_revision.revision_uuid', $published->revision_uuid);
    }

    public function test_missing_revision_download_withdraws_integrity_and_exact_restore_reenables_it(): void
    {
        Storage::fake('pdf');
        $actor = $this->userWithPermissions([
            'pdf.revision.download',
            'pdf.evidence_hold.manage',
        ]);
        $bytes = '%PDF-1.7 published restore bytes';
        [$document, $published] = $this->publishedDocument($actor, $bytes);
        Sanctum::actingAs($actor);

        $this->get("/api/pdf/revisions/{$published->revision_uuid}/download")
            ->assertConflict();

        $this->assertSame('unavailable', $published->refresh()->integrity_state);
        $this->assertSame('hold', $document->refresh()->integrity_state);
        $this->assertSame(1, (int) $document->integrity_hold_mask);
        $this->assertSame(1, (int) $document->integrity_version);
        $this->assertDatabaseHas('pdf_document_publication_events', [
            'document_id' => $document->id,
            'revision_id' => $published->id,
            'event_type' => 'integrity_withdrawn',
            'reason_code' => 'REVISION_DOWNLOAD_INTEGRITY_FAILURE',
        ]);
        $this->assertDatabaseHas('pdf_document_evidence_holds', [
            'document_id' => $document->id,
            'reason_bit' => 8,
            'state' => 'active',
            'installed_by_id' => null,
        ]);

        Storage::disk('pdf')->put($published->file_path, $bytes);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSignaturePdf')->once()->andReturn([
            'signatureCount' => 0,
        ]);
        $this->app->instance(PdfRendererClient::class, $renderer);
        $restored = app(PdfRevisionIntegrityService::class)->restore(
            $published,
            $actor,
            'TRUSTED_BACKUP_EXACT_BYTES_RESTORED',
        );

        $this->assertSame('ready', $restored->integrity_state);
        $this->assertSame('ok', $document->refresh()->integrity_state);
        $this->assertSame(0, (int) $document->integrity_hold_mask);
        $this->assertSame(2, (int) $document->integrity_version);
        $this->assertDatabaseHas('pdf_document_publication_events', [
            'document_id' => $document->id,
            'revision_id' => $published->id,
            'event_type' => 'integrity_restored',
            'reason_code' => 'TRUSTED_BACKUP_EXACT_BYTES_RESTORED',
        ]);
        $this->get("/api/pdf/revisions/{$published->revision_uuid}/download")
            ->assertOk()
            ->assertHeader('X-Pdf-Sha256', hash('sha256', $bytes));
    }

    public function test_revision_integrity_sweeper_withdraws_only_published_ready_revisions(): void
    {
        Storage::fake('pdf');
        $actor = User::factory()->create();
        [$document, $published] = $this->publishedDocument($actor, '%PDF-1.7 missing published');
        DB::table('pdf_document_publication_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'revision_id' => $published->id,
            'event_type' => 'published',
            'occurred_at' => now(),
            'audit_context_hash' => str_repeat('c', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, app(PdfRevisionIntegrityService::class)->sweep());
        $this->assertSame(0, app(PdfRevisionIntegrityService::class)->sweep());
        $this->assertSame('unavailable', $published->refresh()->integrity_state);
        $this->assertSame('hold', $document->refresh()->integrity_state);
        $this->assertDatabaseCount('pdf_document_evidence_holds', 1);
    }

    public function test_revision_integrity_restore_clears_only_the_hold_bits_owned_by_its_manifest(): void
    {
        Storage::fake('pdf');
        [$actor, $document, $operations, $executions, $appearances] = $this->documentHoldFixture();
        $revision = PdfFile::query()->findOrFail($document->published_revision_id);
        $operations[1]->update(['document_evidence_hold_mask' => 8]);
        $executions[1]->update([
            'evidence_hold_mask' => (int) $executions[1]->evidence_hold_mask | 8,
            'evidence_hold_state' => 'active',
        ]);
        $appearances[1]->update([
            'evidence_hold_mask' => (int) $appearances[1]->evidence_hold_mask | 8,
            'evidence_hold_state' => 'active',
        ]);
        $service = app(PdfRevisionIntegrityService::class);

        $this->assertTrue($service->withdraw($revision, 'PUBLISHED_REVISION_BYTES_MISSING'));

        foreach ($operations as $operation) {
            $this->assertNotSame(0, (int) $operation->refresh()->document_evidence_hold_mask & 8);
            $this->assertNull($operation->result_retirement_authorization_hash);
        }
        foreach ([...$executions, ...$appearances] as $target) {
            $this->assertNotSame(0, (int) $target->refresh()->evidence_hold_mask & 8);
        }

        $bytes = '%PDF-1.7 document hold evidence';
        Storage::disk('pdf')->put($revision->file_path, $bytes);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSignaturePdf')->once()->andReturn(['signatureCount' => 0]);
        $this->app->instance(PdfRendererClient::class, $renderer);
        $service = app(PdfRevisionIntegrityService::class);
        $service->restore($revision, $actor, 'EXACT_REVISION_BACKUP_RESTORED');

        $this->assertSame(0, (int) $operations[0]->refresh()->document_evidence_hold_mask & 8);
        $this->assertNotSame(0, (int) $operations[1]->refresh()->document_evidence_hold_mask & 8);
        $this->assertSame(0, (int) $executions[0]->refresh()->evidence_hold_mask & 8);
        $this->assertNotSame(0, (int) $executions[1]->refresh()->evidence_hold_mask & 8);
        $this->assertSame(0, (int) $appearances[0]->refresh()->evidence_hold_mask & 8);
        $this->assertNotSame(0, (int) $appearances[1]->refresh()->evidence_hold_mask & 8);
        $this->assertSame('ready', $revision->refresh()->integrity_state);
        $this->assertSame('ok', $document->refresh()->integrity_state);
    }

    public function test_multiple_withdrawn_revisions_keep_the_document_held_until_the_last_exact_restore(): void
    {
        Storage::fake('pdf');
        $actor = User::factory()->create();
        $firstBytes = '%PDF-1.7 first published revision';
        $secondBytes = '%PDF-1.7 second published revision';
        [$document, $first] = $this->publishedDocument($actor, $firstBytes);
        $second = PdfFile::query()->create([
            'file_name' => 'second.pdf',
            'file_path' => 'workflow/revisions/second.pdf',
            'sha256_hash' => hash('sha256', $secondBytes),
            'file_size' => strlen($secondBytes),
            'created_by' => $actor->name,
            'created_by_id' => $actor->id,
            'document_id' => $document->id,
            'revision_uuid' => (string) Str::uuid(),
            'parent_pdf_file_id' => $first->id,
            'revision_number' => 2,
            'revision_role' => 'handwritten_signature',
            'revision_created_at' => now(),
            'integrity_state' => 'ready',
            'disposition' => 'published',
            'first_published_at' => now(),
        ]);
        $document->update([
            'published_revision_id' => $second->id,
            'publication_version' => 2,
            'next_revision_number' => 3,
        ]);
        foreach ([$first, $second] as $revision) {
            DB::table('pdf_document_publication_events')->insert([
                'event_uuid' => (string) Str::uuid(),
                'document_id' => $document->id,
                'revision_id' => $revision->id,
                'event_type' => 'published',
                'occurred_at' => now(),
                'audit_context_hash' => str_repeat('d', 64),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $service = app(PdfRevisionIntegrityService::class);
        $this->assertTrue($service->withdraw($first, 'FIRST_REVISION_BYTES_MISSING'));
        $this->assertTrue($service->withdraw($second, 'SECOND_REVISION_BYTES_MISSING'));
        $this->assertDatabaseCount('pdf_document_evidence_holds', 2);

        Storage::disk('pdf')->put($first->file_path, $firstBytes);
        Storage::disk('pdf')->put($second->file_path, $secondBytes);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSignaturePdf')->twice()->andReturn(['signatureCount' => 0]);
        $this->app->instance(PdfRendererClient::class, $renderer);
        $service = app(PdfRevisionIntegrityService::class);
        $service->restore($first, $actor, 'FIRST_EXACT_BACKUP_RESTORED');

        $this->assertSame('ready', $first->refresh()->integrity_state);
        $this->assertSame('unavailable', $second->refresh()->integrity_state);
        $this->assertSame('hold', $document->refresh()->integrity_state);
        $this->assertSame(1, (int) $document->integrity_hold_mask);

        $service->restore($second, $actor, 'SECOND_EXACT_BACKUP_RESTORED');
        $this->assertSame('ready', $second->refresh()->integrity_state);
        $this->assertSame('ok', $document->refresh()->integrity_state);
        $this->assertSame(0, (int) $document->integrity_hold_mask);
        $this->assertSame(4, (int) $document->integrity_version);
        $this->assertSame(2, DB::table('pdf_document_evidence_holds')->where('state', 'released')->count());
    }

    public function test_revision_integrity_owner_survives_document_hold_release_then_clears_on_restore(): void
    {
        Storage::fake('pdf');
        [$actor, $document, $operations, $executions, $appearances] = $this->documentHoldFixture();
        $revision = PdfFile::query()->findOrFail($document->published_revision_id);
        $evidence = app(PdfDocumentEvidenceHoldService::class);
        $integrity = app(PdfRevisionIntegrityService::class);

        $evidence->install(
            $document,
            PdfDocumentEvidenceHoldService::RETIREMENT_INTEGRITY,
            'STORAGE_INVESTIGATION',
            $actor,
        );
        $this->assertTrue($integrity->withdraw($revision, 'PUBLISHED_REVISION_BYTES_MISSING'));
        $evidence->release(
            $document,
            PdfDocumentEvidenceHoldService::RETIREMENT_INTEGRITY,
            'STORAGE_INVESTIGATION_CLOSED',
            $actor,
        );

        $this->assertSame(0, (int) $document->refresh()->evidence_hold_mask);
        $this->assertSame('hold', $document->integrity_state);
        foreach ($operations as $operation) {
            $this->assertNotSame(0, (int) $operation->refresh()->document_evidence_hold_mask & 8);
        }
        foreach ([...$executions, ...$appearances] as $target) {
            $this->assertNotSame(0, (int) $target->refresh()->evidence_hold_mask & 8);
        }

        Storage::disk('pdf')->put($revision->file_path, '%PDF-1.7 document hold evidence');
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSignaturePdf')->once()->andReturn(['signatureCount' => 0]);
        $this->app->instance(PdfRendererClient::class, $renderer);
        app(PdfRevisionIntegrityService::class)->restore(
            $revision,
            $actor,
            'EXACT_REVISION_BACKUP_RESTORED',
        );

        foreach ($operations as $operation) {
            $this->assertSame(0, (int) $operation->refresh()->document_evidence_hold_mask & 8);
        }
        foreach ([...$executions, ...$appearances] as $target) {
            $this->assertSame(0, (int) $target->refresh()->evidence_hold_mask & 8);
        }
    }

    public function test_document_hold_owner_survives_revision_restore_then_clears_on_release(): void
    {
        Storage::fake('pdf');
        [$actor, $document, $operations, $executions, $appearances] = $this->documentHoldFixture();
        $revision = PdfFile::query()->findOrFail($document->published_revision_id);
        $integrity = app(PdfRevisionIntegrityService::class);

        $this->assertTrue($integrity->withdraw($revision, 'PUBLISHED_REVISION_BYTES_MISSING'));
        app(PdfDocumentEvidenceHoldService::class)->install(
            $document,
            PdfDocumentEvidenceHoldService::RETIREMENT_INTEGRITY,
            'STORAGE_INVESTIGATION',
            $actor,
        );

        Storage::disk('pdf')->put($revision->file_path, '%PDF-1.7 document hold evidence');
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSignaturePdf')->once()->andReturn(['signatureCount' => 0]);
        $this->app->instance(PdfRendererClient::class, $renderer);
        app(PdfRevisionIntegrityService::class)->restore(
            $revision,
            $actor,
            'EXACT_REVISION_BACKUP_RESTORED',
        );

        $this->assertSame('ok', $document->refresh()->integrity_state);
        $this->assertNotSame(0, (int) $document->evidence_hold_mask & 8);
        foreach ($operations as $operation) {
            $this->assertNotSame(0, (int) $operation->refresh()->document_evidence_hold_mask & 8);
        }
        foreach ([...$executions, ...$appearances] as $target) {
            $this->assertNotSame(0, (int) $target->refresh()->evidence_hold_mask & 8);
        }

        app(PdfDocumentEvidenceHoldService::class)->release(
            $document,
            PdfDocumentEvidenceHoldService::RETIREMENT_INTEGRITY,
            'STORAGE_INVESTIGATION_CLOSED',
            $actor,
        );

        $this->assertSame(0, (int) $document->refresh()->evidence_hold_mask);
        foreach ($operations as $operation) {
            $this->assertSame(0, (int) $operation->refresh()->document_evidence_hold_mask & 8);
        }
        foreach ([...$executions, ...$appearances] as $target) {
            $this->assertSame(0, (int) $target->refresh()->evidence_hold_mask & 8);
        }
    }

    public function test_appearance_retirement_stages_then_purges_after_recoverable_grace_period(): void
    {
        Storage::fake('pdf');
        $actor = User::factory()->create();
        [$document, $published] = $this->publishedDocument($actor);
        $workflow = PdfSigningWorkflow::query()->create([
            'workflow_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'workflow_generation' => 1,
            'current_revision_id' => $published->id,
            'status' => 'completed',
            'created_by_id' => $actor->id,
        ]);
        $act = PdfSigningAct::query()->create([
            'logical_act_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'plan_generation' => 1,
            'semantic_role' => 'issuer',
            'pdf_signature_role' => 'approval',
            'sequence' => 3,
            'field_name' => 'issuer',
            'status' => 'completed',
        ]);
        $request = PdfSigningRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'signing_act_id' => $act->id,
            'sequence' => 3,
            'request_type' => 'handwritten',
            'assigned_user_id' => $actor->id,
            'signing_policy_version_id' => $this->policy()->id,
            'status' => 'signed',
        ]);
        $bytes = 'canonical-signature-png';
        $path = 'workflow/appearances/retention-test.png';
        Storage::disk('pdf')->put($path, $bytes);
        $appearance = PdfSignatureAppearanceArtifact::query()->create([
            'appearance_uuid' => (string) Str::uuid(),
            'request_id' => $request->id,
            'created_by_id' => $actor->id,
            'artifact_type' => 'handwriting',
            'canonical_image_sha256' => hash('sha256', $bytes),
            'appearance_manifest_hash' => str_repeat('a', 64),
            'slot_manifest' => [],
            'width' => 10,
            'height' => 10,
            'crop_box' => [],
            'renderer_version' => 'test',
            'state' => 'consumed',
            'retention_until' => now()->subMinute(),
            'file_path' => $path,
        ]);

        $retention = app(PdfAppearanceRetentionService::class);
        $opened = $retention->openVerifiedDescriptor($appearance);
        $this->assertSame(hash('sha256', $bytes), $opened['sha256']);
        $this->assertSame(strlen($bytes), $opened['size']);

        $renameWonPath = "workflow/appearance-retirement/{$appearance->appearance_uuid}-1.png";
        $this->runRetirementCrashWorker('move', $path, $renameWonPath);
        $appearance->update([
            'retirement_state' => 'stage_intent',
            'retirement_epoch' => 1,
            'retirement_staged_path' => $renameWonPath,
            'lock_version' => $appearance->lock_version + 1,
        ]);
        Storage::disk('pdf')->put($path, $bytes);
        try {
            $retention->sweep();
            $this->fail('Duplicate appearance bytes must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('ambiguous duplicate', $exception->getMessage());
        }
        $this->assertSame('stage_intent', $appearance->fresh()->retirement_state);
        Storage::disk('pdf')->delete($path);
        $retention->sweep();
        $appearance->refresh();
        $this->assertSame('staged', $appearance->retirement_state);
        Storage::disk('pdf')->assertMissing($path);
        Storage::disk('pdf')->assertExists($appearance->retirement_staged_path);

        $stagedPath = $appearance->retirement_staged_path;
        $appearance->update([
            'evidence_hold_mask' => 1,
            'evidence_hold_state' => 'active',
        ]);
        $this->runRetirementCrashWorker('move', (string) $stagedPath, $path);
        $this->assertSame('staged', $appearance->fresh()->retirement_state);
        $retention->sweep();
        $appearance->refresh();
        $this->assertSame('none', $appearance->retirement_state);
        Storage::disk('pdf')->assertExists($path);
        Storage::disk('pdf')->assertMissing($stagedPath);

        $appearance->update([
            'evidence_hold_mask' => 0,
            'evidence_hold_state' => 'none',
        ]);
        $retention->sweep();
        $appearance->refresh();
        $this->assertSame('staged', $appearance->retirement_state);

        $this->travel(2)->days();
        $appearance->update([
            'retirement_state' => 'purge_intent',
            'lock_version' => $appearance->lock_version + 1,
        ]);
        Storage::disk('pdf')->put($path, $bytes);
        try {
            $retention->sweep();
            $this->fail('Unexpected canonical appearance bytes must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('unexpected canonical', $exception->getMessage());
        }
        $this->assertSame('purge_intent', $appearance->fresh()->retirement_state);
        Storage::disk('pdf')->delete($path);
        $this->runRetirementCrashWorker('unlink', (string) $appearance->retirement_staged_path);
        $retention->sweep();
        $appearance->refresh();
        $this->assertSame('retired', $appearance->retirement_state);
        $this->assertSame('expired', $appearance->state);
        $this->assertNotNull($appearance->deleted_at);
        $this->assertSame($bytes, stream_get_contents($opened['stream']));
        $this->assertSame([
            'APPEARANCE_RETIRE_STAGED',
            'APPEARANCE_RETIRE_RESTORED',
            'APPEARANCE_RETIRE_STAGE_INTENT',
            'APPEARANCE_RETIRE_STAGED',
            'APPEARANCE_RETIRED',
        ], DB::table('activity_log')
            ->where('log_name', 'pdf_appearance_retirement')
            ->orderBy('id')
            ->pluck('event')
            ->all());
        fclose($opened['stream']);
    }

    public function test_manual_review_can_resume_same_workflow_only_when_private_key_absence_is_proven(): void
    {
        $admin = $this->userWithPermissions(['pdf.manual_review.resolve']);
        [$document, $published] = $this->publishedDocument($admin);
        $workflow = PdfSigningWorkflow::query()->create([
            'workflow_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'workflow_generation' => 1,
            'current_revision_id' => $published->id,
            'status' => 'manual_review',
            'created_by_id' => $admin->id,
        ]);
        $act = PdfSigningAct::query()->create([
            'logical_act_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'plan_generation' => 1,
            'semantic_role' => 'reviewer',
            'pdf_signature_role' => 'approval',
            'sequence' => 2,
            'field_name' => 'reviewer',
            'status' => 'planned',
        ]);
        $request = PdfSigningRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'signing_act_id' => $act->id,
            'sequence' => 2,
            'request_type' => 'handwritten',
            'assigned_user_id' => $admin->id,
            'signing_policy_version_id' => $this->policy()->id,
            'status' => 'manual_review',
        ]);
        $operation = PdfSigningOperation::query()->create([
            'operation_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'manual-review-test',
            'idempotency_scope_key' => 'manual-review-test',
            'scope_type' => 'request',
            'actor_user_id' => $admin->id,
            'document_id' => $document->id,
            'workflow_id' => $workflow->id,
            'request_id' => $request->id,
            'action' => 'fill_signature_field',
            'input_fingerprint' => str_repeat('1', 64),
            'operation_input_manifest_hash' => str_repeat('2', 64),
            'state' => 'manual_review',
            'stage' => 'done',
            'audit_context' => [],
            'audit_context_hash' => str_repeat('3', 64),
        ]);
        $appearance = PdfSignatureAppearanceArtifact::query()->create([
            'appearance_uuid' => (string) Str::uuid(),
            'request_id' => $request->id,
            'created_by_id' => $admin->id,
            'artifact_type' => 'handwriting',
            'canonical_image_sha256' => str_repeat('4', 64),
            'appearance_manifest_hash' => str_repeat('5', 64),
            'slot_manifest' => [],
            'width' => 10,
            'height' => 10,
            'crop_box' => [],
            'renderer_version' => 'test',
            'state' => 'quarantined',
            'evidence_hold_mask' => 5,
            'evidence_hold_state' => 'active',
            'claimed_by_operation_id' => $operation->id,
            'file_path' => 'workflow/appearances/manual.png',
        ]);
        $workflow->update(['active_operation_id' => $operation->id]);
        $document->update([
            'active_workflow_id' => $workflow->id,
            'active_operation_id' => $operation->id,
            'integrity_state' => 'hold',
            'integrity_hold_mask' => 8,
        ]);

        $resolved = app(ResolvePdfSigningManualReviewService::class)->resolve(
            $operation,
            'confirmed_no_private_key',
            'FORENSIC_REVIEW_COMPLETE',
            str_repeat('a', 64),
            $admin,
        );

        $this->assertSame('failed', $resolved->state);
        $this->assertSame('available', $request->refresh()->status);
        $this->assertSame('ready', $workflow->refresh()->status);
        $this->assertSame('ok', $document->refresh()->integrity_state);
        $this->assertSame('none', $appearance->refresh()->evidence_hold_state);
        $this->assertDatabaseCount('pdf_signing_operation_events', 1);
    }

    public function test_manual_review_adopts_only_verified_completed_result_through_result_only_job(): void
    {
        Bus::fake();
        [$admin, $document, $workflow, $request, $operation, $appearance] = $this->manualReviewFixture();
        $bytes = '%PDF-1.7 verified completed result';
        $sha256 = hash('sha256', $bytes);
        PdfJavaSigningExecution::query()->create([
            'operation_uuid' => $operation->operation_uuid,
            'operation_input_manifest_hash' => $operation->operation_input_manifest_hash,
            'input_fingerprint' => $operation->input_fingerprint,
            'policy_hash' => $operation->policy_hash,
            'authorized_lease_epoch' => (int) $operation->lease_epoch,
            'claimed_at' => now()->subMinute(),
            'private_key_started_at' => now()->subMinute(),
            'completed_at' => now(),
            'terminal_at' => now(),
            'state' => 'completed',
            'result_path' => '/java/results/'.$operation->operation_uuid.'.pdf',
            'result_sha256' => $sha256,
            'result_size' => strlen($bytes),
            'result_file_key' => 'test-file-key',
            'validation_report_hash' => str_repeat('9', 64),
            'result_integrity_state' => 'available',
            'evidence_hold_mask' => 5,
            'evidence_hold_state' => 'active',
        ]);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('signingExecutionResult')->twice()->andReturn([
            'body' => $bytes,
            'sha256' => $sha256,
            'size' => strlen($bytes),
        ]);
        $renderer->shouldReceive('verifySignatureBytes')->twice()->andReturn([
            'documentCurrentState' => 'valid',
            'docMdpPermission' => 2,
            'signatures' => [[], []],
        ]);
        $renderer->shouldReceive('inspectSignatureBytes')->twice()->andReturn([
            'signatureCount' => 2,
            'fields' => [[
                'fieldName' => 'reviewer',
                'signed' => true,
            ]],
        ]);
        $this->app->instance(PdfRendererClient::class, $renderer);

        $resolved = app(ResolvePdfSigningManualReviewService::class)->resolve(
            $operation,
            'adopt_completed',
            'VERIFIED_JAVA_RESULT',
            str_repeat('b', 64),
            $admin,
        );

        $this->assertSame('processing', $resolved->state);
        $this->assertSame('java_polling', $resolved->stage);
        $this->assertSame('completed', $resolved->java_execution_state);
        $this->assertSame('signing', $request->refresh()->status);
        $this->assertSame('claimed', $appearance->refresh()->state);
        $this->assertSame('active', $appearance->evidence_hold_state);
        $this->assertSame('hold', $document->refresh()->integrity_state);
        $outbox = PdfOperationOutbox::query()->where('operation_id', $operation->id)->sole();
        $this->assertSame('resume_pdf_operation_from_java_result', $outbox->job_type);

        $this->assertSame(1, app(PdfOperationOutboxDispatcher::class)->dispatchPending());
        Bus::assertDispatched(
            ResumePdfOperationFromJavaResult::class,
            fn (ResumePdfOperationFromJavaResult $job): bool => $job->operationUuid === $operation->operation_uuid,
        );

        $oldLeaseEpoch = (int) $operation->lease_epoch;
        (new ResumePdfOperationFromJavaResult($operation->operation_uuid))->handle(
            $renderer,
            app(PdfImmutableFileStore::class),
            app(PdfRevisionService::class),
        );
        $completed = $operation->refresh();
        $this->assertSame('completed', $completed->state);
        $this->assertSame($oldLeaseEpoch + 1, (int) $completed->lease_epoch);
        $this->assertSame('signed', $request->refresh()->status);
        $this->assertSame('none', $appearance->refresh()->evidence_hold_state);
        $this->assertSame('ok', $document->refresh()->integrity_state);
    }

    public function test_manual_review_confirms_no_usable_result_without_reclassifying_java_history(): void
    {
        [$admin, $document, $workflow, $request, $operation, $appearance] = $this->manualReviewFixture();
        $execution = PdfJavaSigningExecution::query()->create([
            'operation_uuid' => $operation->operation_uuid,
            'operation_input_manifest_hash' => $operation->operation_input_manifest_hash,
            'input_fingerprint' => $operation->input_fingerprint,
            'policy_hash' => $operation->policy_hash,
            'authorized_lease_epoch' => (int) $operation->lease_epoch,
            'claimed_at' => now()->subMinute(),
            'private_key_started_at' => now()->subMinute(),
            'terminal_at' => now(),
            'state' => 'outcome_unknown',
            'result_integrity_state' => 'not_applicable',
            'evidence_hold_mask' => 5,
            'evidence_hold_state' => 'active',
        ]);

        $resolved = app(ResolvePdfSigningManualReviewService::class)->resolve(
            $operation,
            'confirmed_no_usable_result',
            'FORENSIC_NO_RESULT',
            str_repeat('c', 64),
            $admin,
        );

        $this->assertSame('irreversible_failed', $resolved->state);
        $this->assertSame('new_generation_only', $resolved->error_retryability);
        $this->assertSame('failed', $request->refresh()->status);
        $this->assertSame('failed', $workflow->refresh()->status);
        $this->assertSame('none', $appearance->refresh()->evidence_hold_state);
        $this->assertSame('none', $execution->refresh()->evidence_hold_state);
        $this->assertSame('outcome_unknown', $execution->state);
        $this->assertSame('ok', $document->refresh()->integrity_state);
        $this->assertDatabaseHas('pdf_java_signing_execution_events', [
            'operation_uuid' => $operation->operation_uuid,
            'event_type' => 'EVIDENCE_HOLD_RELEASED',
        ]);
    }

    public function test_java_result_retirement_is_authorized_only_after_formal_revision_verification(): void
    {
        Storage::fake('pdf');
        config(['pdf_service.enabled' => true]);
        $actor = User::factory()->create();
        $bytes = '%PDF-1.7 formally verified signed revision';
        [$document, $revision] = $this->publishedDocument($actor, $bytes);
        Storage::disk('pdf')->put($revision->file_path, $bytes);
        $policy = $this->policy();
        $operation = PdfSigningOperation::query()->create([
            'operation_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'retirement-auth-test',
            'idempotency_scope_key' => 'retirement-auth-test',
            'scope_type' => 'request',
            'actor_user_id' => $actor->id,
            'document_id' => $document->id,
            'action' => 'fill_signature_field',
            'input_fingerprint' => str_repeat('1', 64),
            'operation_input_manifest_hash' => str_repeat('2', 64),
            'signing_policy_version_id' => $policy->id,
            'policy_hash' => $policy->policy_hash,
            'result_revision_id' => $revision->id,
            'result_revision_uuid' => $revision->revision_uuid,
            'result_sha256' => $revision->sha256_hash,
            'result_size' => $revision->file_size,
            'state' => 'completed',
            'stage' => 'done',
            'audit_context' => [],
            'audit_context_hash' => str_repeat('3', 64),
        ]);
        PdfJavaSigningExecution::query()->create([
            'operation_uuid' => $operation->operation_uuid,
            'operation_input_manifest_hash' => $operation->operation_input_manifest_hash,
            'input_fingerprint' => $operation->input_fingerprint,
            'policy_hash' => $policy->policy_hash,
            'authorized_lease_epoch' => 1,
            'claimed_at' => now()->subDays(8),
            'completed_at' => now()->subDays(8),
            'terminal_at' => now()->subDays(8),
            'state' => 'completed',
            'result_path' => '/java/results/'.$operation->operation_uuid.'.pdf',
            'result_sha256' => $revision->sha256_hash,
            'result_size' => $revision->file_size,
            'result_integrity_state' => 'available',
            'retention_until' => now()->subMinute(),
            'retirement_phase' => 'none',
            'evidence_hold_state' => 'none',
        ]);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('verifySignaturePdf')->once()->andReturn([
            'documentCurrentState' => 'valid',
            'signatures' => [[], [], []],
        ]);
        $this->app->instance(PdfRendererClient::class, $renderer);

        $this->assertSame(1, app(AuthorizeJavaResultRetirementService::class)->sweep());

        $operation->refresh();
        $this->assertNotNull($operation->result_retirement_authorization_hash);
        $this->assertSame($revision->revision_uuid, $operation->result_retirement_authorization_manifest['formal_revision_uuid']);
        $this->assertSame($revision->sha256_hash, $operation->result_retirement_authorization_manifest['execution_result_sha256']);
        $this->assertTrue($operation->result_retirement_authorization_expires_at->isFuture());
    }

    public function test_document_wide_hold_is_atomic_restores_staged_bytes_and_preserves_preexisting_bits(): void
    {
        Storage::fake('pdf');
        [$actor, $document, $operations, $executions, $appearances] = $this->documentHoldFixture();
        $retention = app(PdfAppearanceRetentionService::class);
        $retention->sweep();
        $staged = $appearances[0]->refresh();
        $stagedEpoch = (int) $staged->retirement_epoch;
        $this->assertSame('staged', $staged->retirement_state);

        $held = app(PdfDocumentEvidenceHoldService::class)->install(
            $document,
            PdfDocumentEvidenceHoldService::MANUAL_REVIEW,
            'DOCUMENT_INVESTIGATION',
            $actor,
        );

        $this->assertSame(1, $held->evidence_hold_mask);
        $this->assertSame('active', $held->evidence_hold_state);
        $this->assertSame('ok', $held->integrity_state);
        $this->assertSame(1, $held->integrity_version);
        $this->getJson("/api/public/pdf/documents/{$held->document_public_id}")
            ->assertOk()
            ->assertJsonPath('data.registration_state', 'published');
        foreach ($operations as $operation) {
            $this->assertNull($operation->refresh()->result_retirement_authorization_hash);
            $this->assertSame(1, (int) $operation->document_evidence_hold_mask);
        }
        $this->assertSame(1, $executions[0]->refresh()->evidence_hold_mask);
        $this->assertSame(1, $executions[1]->refresh()->evidence_hold_mask);
        $this->assertSame(1, $appearances[0]->refresh()->evidence_hold_mask);
        $this->assertSame(1, $appearances[1]->refresh()->evidence_hold_mask);

        try {
            app(PdfDocumentEvidenceHoldService::class)->release(
                $held,
                PdfDocumentEvidenceHoldService::MANUAL_REVIEW,
                'RELEASE_BEFORE_RESTORE',
                $actor,
            );
            $this->fail('Release must fail while a target is still staged.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('PDF_DOCUMENT_EVIDENCE_HOLD_RETIREMENT_NOT_RESTORED', $exception->getMessage());
        }
        $this->assertSame('active', $document->refresh()->evidence_hold_state);

        $retention->sweep();
        $restoredAppearance = $appearances[0]->refresh();
        $this->assertSame('none', $restoredAppearance->retirement_state);
        $this->assertSame($stagedEpoch + 1, (int) $restoredAppearance->retirement_epoch);
        Storage::disk('pdf')->assertExists($appearances[0]->file_path);

        $released = app(PdfDocumentEvidenceHoldService::class)->release(
            $document,
            PdfDocumentEvidenceHoldService::MANUAL_REVIEW,
            'INVESTIGATION_CLOSED',
            $actor,
        );
        $this->assertSame(0, $released->evidence_hold_mask);
        $this->assertSame('none', $released->evidence_hold_state);
        $this->assertSame('ok', $released->integrity_state);
        $this->assertSame(2, $released->integrity_version);
        $this->assertSame(0, $executions[0]->refresh()->evidence_hold_mask);
        $this->assertSame(1, $executions[1]->refresh()->evidence_hold_mask);
        $this->assertSame(0, $appearances[0]->refresh()->evidence_hold_mask);
        $this->assertSame(1, $appearances[1]->refresh()->evidence_hold_mask);
        foreach ($operations as $operation) {
            $this->assertSame(0, (int) $operation->refresh()->document_evidence_hold_mask);
        }
        $this->assertDatabaseHas('pdf_document_evidence_holds', [
            'document_id' => $document->id,
            'reason_bit' => 1,
            'state' => 'released',
        ]);
        $this->assertDatabaseHas('pdf_document_publication_events', [
            'document_id' => $document->id,
            'event_type' => 'evidence_hold_added',
            'old_integrity_version' => 0,
            'new_integrity_version' => 1,
        ]);
        $this->assertDatabaseHas('pdf_document_publication_events', [
            'document_id' => $document->id,
            'event_type' => 'evidence_hold_released',
            'old_integrity_version' => 1,
            'new_integrity_version' => 2,
        ]);
    }

    public function test_document_wide_hold_rejects_retired_evidence_without_partial_install(): void
    {
        Storage::fake('pdf');
        [$actor, $document, $operations, $executions] = $this->documentHoldFixture();
        $executions[1]->update([
            'result_integrity_state' => 'retired',
            'retirement_phase' => 'retired',
            'evidence_hold_mask' => 0,
            'evidence_hold_state' => 'none',
            'bytes_deleted_at' => now(),
        ]);

        try {
            app(PdfDocumentEvidenceHoldService::class)->install(
                $document,
                PdfDocumentEvidenceHoldService::MANUAL_REVIEW,
                'LATE_DOCUMENT_INVESTIGATION',
                $actor,
            );
            $this->fail('A document hold cannot claim evidence that was already retired.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('PDF_DOCUMENT_EVIDENCE_ALREADY_RETIRED', $exception->getMessage());
        }

        $this->assertSame(0, $document->refresh()->evidence_hold_mask);
        $this->assertSame(0, $executions[0]->refresh()->evidence_hold_mask);
        $this->assertNotNull($operations[0]->refresh()->result_retirement_authorization_hash);
        $this->assertDatabaseMissing('pdf_document_evidence_holds', [
            'document_id' => $document->id,
            'state' => 'active',
        ]);
    }

    public function test_document_wide_hold_rejects_purge_intent_after_unlink_without_partial_install(): void
    {
        Storage::fake('pdf');
        [$actor, $document, $operations, $executions] = $this->documentHoldFixture();
        $purging = $executions[1];
        $purging->update([
            'result_integrity_state' => 'retiring',
            'retirement_phase' => 'purge_intent',
            'retirement_epoch' => 3,
            'retirement_staged_path' => "/java/results/{$purging->operation_uuid}.pdf.retirement-3",
        ]);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSigningRetirementEvidence')
            ->andReturnUsing(function (
                string $operationUuid,
                int $epoch,
                string $phase,
                string $sha256,
                int $size,
            ) use ($purging): array {
                return [
                    'operationUuid' => $operationUuid,
                    'retirementEpoch' => $epoch,
                    'retirementPhase' => $phase,
                    'expectedSha256' => $sha256,
                    'expectedSize' => $size,
                    'canonicalPresent' => $operationUuid !== $purging->operation_uuid,
                    'stagedPresent' => false,
                    'state' => $operationUuid === $purging->operation_uuid ? 'missing' : 'canonical',
                ];
            });
        $this->app->instance(PdfRendererClient::class, $renderer);

        try {
            app(PdfDocumentEvidenceHoldService::class)->install(
                $document,
                PdfDocumentEvidenceHoldService::LEGAL_HOLD,
                'LATE_LEGAL_HOLD_AFTER_UNLINK',
                $actor,
                now()->addMonth(),
            );
            $this->fail('A hold cannot become active after exact purge bytes were unlinked.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('PDF_DOCUMENT_EVIDENCE_ALREADY_RETIRED', $exception->getMessage());
        }

        $this->assertSame(0, $document->refresh()->evidence_hold_mask);
        foreach ($operations as $operation) {
            $this->assertSame(0, (int) $operation->refresh()->document_evidence_hold_mask);
            $this->assertNotNull($operation->result_retirement_authorization_hash);
        }
        $this->assertDatabaseMissing('pdf_document_evidence_holds', [
            'document_id' => $document->id,
            'state' => 'active',
        ]);
    }

    public function test_document_wide_legal_hold_release_restores_preexisting_deadlines(): void
    {
        Storage::fake('pdf');
        [$actor, $document, , $executions, $appearances] = $this->documentHoldFixture();
        $originalDeadline = now()->addDays(2)->startOfSecond();
        $requestedDeadline = now()->addDays(5)->startOfSecond();
        $document->update([
            'evidence_hold_mask' => PdfDocumentEvidenceHoldService::LEGAL_HOLD,
            'evidence_hold_state' => 'active',
            'legal_hold_until' => $originalDeadline,
        ]);
        foreach ($executions as $execution) {
            $execution->update([
                'evidence_hold_mask' => (int) $execution->evidence_hold_mask | PdfDocumentEvidenceHoldService::LEGAL_HOLD,
                'evidence_hold_state' => 'active',
                'legal_hold_until' => $originalDeadline,
            ]);
        }
        foreach ($appearances as $appearance) {
            $appearance->update([
                'evidence_hold_mask' => (int) $appearance->evidence_hold_mask | PdfDocumentEvidenceHoldService::LEGAL_HOLD,
                'evidence_hold_state' => 'active',
                'legal_hold_until' => $originalDeadline,
            ]);
        }

        $service = app(PdfDocumentEvidenceHoldService::class);
        $held = $service->install(
            $document,
            PdfDocumentEvidenceHoldService::LEGAL_HOLD,
            'DOCUMENT_LEGAL_REQUEST',
            $actor,
            $requestedDeadline,
        );
        $this->assertTrue($held->legal_hold_until->equalTo($requestedDeadline));
        foreach ([...$executions, ...$appearances] as $target) {
            $this->assertTrue($target->refresh()->legal_hold_until->equalTo($requestedDeadline));
        }

        $released = $service->release(
            $held,
            PdfDocumentEvidenceHoldService::LEGAL_HOLD,
            'DOCUMENT_LEGAL_REQUEST_RELEASED',
            $actor,
        );
        $this->assertSame(PdfDocumentEvidenceHoldService::LEGAL_HOLD, $released->evidence_hold_mask);
        $this->assertTrue($released->legal_hold_until->equalTo($originalDeadline));
        foreach ([...$executions, ...$appearances] as $target) {
            $this->assertTrue($target->refresh()->legal_hold_until->equalTo($originalDeadline));
            $this->assertNotSame(0, (int) $target->evidence_hold_mask & PdfDocumentEvidenceHoldService::LEGAL_HOLD);
        }
    }

    /** @return array{PdfDocument, PdfFile} */
    private function runRetirementCrashWorker(string $action, string $source, ?string $target = null): void
    {
        $disk = Storage::disk('pdf');
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/PdfRetirementCrashWorker.php'),
            $action,
            $disk->path($source),
            $target === null ? '-' : $disk->path($target),
        ]);
        $process->setTimeout(10);
        $killed = false;
        try {
            $process->run();
        } catch (ProcessSignaledException) {
            $killed = $process->getTermSignal() === 9;
        }

        $this->assertTrue(
            $killed || $process->getExitCode() === 137,
            $process->getErrorOutput().$process->getOutput(),
        );
        $this->assertStringContainsString('retirement-file-action-durable', $process->getOutput());
    }

    private function publishedDocument(User $actor, string $bytes = '%PDF-1.7 published'): array
    {
        $document = PdfDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'document_public_id' => Str::random(48),
            'organization_scope' => 'test',
            'authoritative_report_number' => 'R-'.Str::random(8),
            'normalized_report_number' => 'R-'.Str::random(16),
            'status' => 'published',
            'created_by_id' => $actor->id,
        ]);
        $revision = PdfFile::query()->create([
            'file_name' => 'published.pdf',
            'file_path' => 'workflow/revisions/published.pdf',
            'sha256_hash' => hash('sha256', $bytes),
            'file_size' => strlen($bytes),
            'created_by' => $actor->name,
            'created_by_id' => $actor->id,
            'document_id' => $document->id,
            'revision_uuid' => (string) Str::uuid(),
            'revision_number' => 1,
            'revision_role' => 'handwritten_signature',
            'revision_created_at' => now(),
            'integrity_state' => 'ready',
            'disposition' => 'published',
            'first_published_at' => now(),
        ]);
        $document->update([
            'published_revision_id' => $revision->id,
            'publication_version' => 1,
            'next_revision_number' => 2,
        ]);

        return [$document->refresh(), $revision];
    }

    private function policy(): PdfSigningPolicyVersion
    {
        $manifest = ['profile' => 'B-T', 'version' => Str::random(8)];

        return PdfSigningPolicyVersion::query()->create([
            'version_uuid' => (string) Str::uuid(),
            'policy_hash' => hash('sha256', CanonicalJson::encode($manifest)),
            'immutable_at' => now(),
            'pades_profile' => 'B-T',
            'digest_algorithm_oid' => '2.16.840.1.101.3.4.2.1',
            'signature_algorithm_oid' => '1.2.840.113549.1.1.11',
            'organization_certificate_fingerprints' => [str_repeat('d', 64)],
            'signing_material_version' => 'test-v1',
            'key_locator' => 'test',
            'tsa_url_set' => ['http://tsa.invalid'],
            'tsa_timeout_seconds' => 10,
            'trust_bundle_hash' => str_repeat('e', 64),
            'revocation_policy' => ['mode' => 'required'],
            'reserved_size' => 32768,
            'pre_private_key_retry_backoff_seconds' => [2],
            'pre_private_key_retryable_error_codes' => ['DB_UNAVAILABLE'],
            'java_execution_registration_timeout_seconds' => 15,
            'java_execution_timeout_seconds' => 90,
            'java_status_poll_policy' => ['initial' => 2, 'max' => 10],
            'java_result_min_bytes_per_second' => 1048576,
            'java_result_read_timeout_seconds' => 60,
            'generated_revision_max_bytes' => 33554432,
            'max_signature_increment_bytes' => 2097152,
            'policy_manifest' => $manifest,
            'config_bundle_hash' => str_repeat('f', 64),
        ]);
    }

    /** @return array{User, PdfDocument, PdfSigningWorkflow, PdfSigningRequest, PdfSigningOperation, PdfSignatureAppearanceArtifact} */
    private function manualReviewFixture(): array
    {
        $admin = $this->userWithPermissions(['pdf.manual_review.resolve']);
        [$document, $published] = $this->publishedDocument($admin);
        $policy = $this->policy();
        $workflow = PdfSigningWorkflow::query()->create([
            'workflow_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'workflow_generation' => 1,
            'current_revision_id' => $published->id,
            'status' => 'manual_review',
            'created_by_id' => $admin->id,
        ]);
        $act = PdfSigningAct::query()->create([
            'logical_act_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'plan_generation' => 1,
            'semantic_role' => 'reviewer',
            'pdf_signature_role' => 'approval',
            'sequence' => 2,
            'field_name' => 'reviewer',
            'status' => 'planned',
        ]);
        $request = PdfSigningRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'signing_act_id' => $act->id,
            'sequence' => 2,
            'request_type' => 'handwritten',
            'assigned_user_id' => $admin->id,
            'signing_policy_version_id' => $policy->id,
            'status' => 'manual_review',
            'expected_source_revision_id' => $published->id,
            'expected_source_sha256' => $published->sha256_hash,
        ]);
        $issuerAct = PdfSigningAct::query()->create([
            'logical_act_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'plan_generation' => 1,
            'semantic_role' => 'issuer',
            'pdf_signature_role' => 'approval',
            'sequence' => 3,
            'field_name' => 'issuer',
            'status' => 'planned',
        ]);
        PdfSigningRequest::query()->create([
            'request_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'signing_act_id' => $issuerAct->id,
            'sequence' => 3,
            'predecessor_request_id' => $request->id,
            'request_type' => 'handwritten',
            'assigned_user_id' => $admin->id,
            'signing_policy_version_id' => $policy->id,
            'status' => 'pending',
        ]);
        PdfSigningField::query()->create([
            'field_uuid' => (string) Str::uuid(),
            'workflow_id' => $workflow->id,
            'signing_act_id' => $act->id,
            'request_id' => $request->id,
            'field_name' => 'reviewer',
            'field_type' => 'signature',
            'activation_mode' => 'active',
            'binding_mode' => 'precreated',
            'lock_policy' => 'include_self_only',
            'prepared_revision_id' => $published->id,
            'status' => 'active',
        ]);
        $operation = PdfSigningOperation::query()->create([
            'operation_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'manual-review-'.Str::uuid(),
            'idempotency_scope_key' => 'manual-review-'.Str::uuid(),
            'scope_type' => 'request',
            'actor_user_id' => $admin->id,
            'document_id' => $document->id,
            'workflow_id' => $workflow->id,
            'request_id' => $request->id,
            'action' => 'fill_signature_field',
            'input_fingerprint' => str_repeat('1', 64),
            'operation_input_manifest_hash' => str_repeat('2', 64),
            'expected_source_revision_id' => $published->id,
            'expected_source_sha256' => $published->sha256_hash,
            'signing_policy_version_id' => $policy->id,
            'policy_hash' => $policy->policy_hash,
            'config_bundle_hash' => $policy->config_bundle_hash,
            'appearance_manifest_hash' => str_repeat('5', 64),
            'appearance_sha256' => str_repeat('4', 64),
            'pdf_signature_role' => 'approval',
            'target_field_name' => 'reviewer',
            'expected_certificate_fingerprint' => str_repeat('d', 64),
            'field_lock_policy_hash' => str_repeat('e', 64),
            'result_revision_uuid' => (string) Str::uuid(),
            'state' => 'manual_review',
            'stage' => 'done',
            'java_execution_state' => 'outcome_unknown',
            'audit_context' => [],
            'audit_context_hash' => str_repeat('3', 64),
        ]);
        $appearance = PdfSignatureAppearanceArtifact::query()->create([
            'appearance_uuid' => (string) Str::uuid(),
            'request_id' => $request->id,
            'created_by_id' => $admin->id,
            'artifact_type' => 'handwriting',
            'canonical_image_sha256' => str_repeat('4', 64),
            'appearance_manifest_hash' => str_repeat('5', 64),
            'slot_manifest' => [],
            'width' => 10,
            'height' => 10,
            'crop_box' => [],
            'renderer_version' => 'test',
            'state' => 'quarantined',
            'evidence_hold_mask' => 5,
            'evidence_hold_state' => 'active',
            'claimed_by_operation_id' => $operation->id,
            'file_path' => 'workflow/appearances/manual-'.Str::uuid().'.png',
        ]);
        $workflow->update(['active_operation_id' => $operation->id]);
        $document->update([
            'active_workflow_id' => $workflow->id,
            'active_operation_id' => $operation->id,
            'integrity_state' => 'hold',
            'integrity_hold_mask' => 8,
        ]);

        return [$admin, $document->refresh(), $workflow, $request, $operation, $appearance];
    }

    /** @return array{User, PdfDocument, array<PdfSigningOperation>, array<PdfJavaSigningExecution>, array<PdfSignatureAppearanceArtifact>} */
    private function documentHoldFixture(): array
    {
        $actor = $this->userWithPermissions(['pdf.evidence_hold.manage']);
        [$document, $published] = $this->publishedDocument($actor, '%PDF-1.7 document hold evidence');
        $policy = $this->policy();
        $workflow = PdfSigningWorkflow::query()->create([
            'workflow_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'workflow_generation' => 1,
            'current_revision_id' => $published->id,
            'status' => 'completed',
            'created_by_id' => $actor->id,
        ]);
        $operations = [];
        $executions = [];
        $appearances = [];

        foreach ([1, 2] as $sequence) {
            $act = PdfSigningAct::query()->create([
                'logical_act_uuid' => (string) Str::uuid(),
                'document_id' => $document->id,
                'plan_generation' => 1,
                'semantic_role' => $sequence === 1 ? 'inspector' : 'reviewer',
                'pdf_signature_role' => $sequence === 1 ? 'certification_p2' : 'approval',
                'sequence' => $sequence,
                'field_name' => "hold_field_{$sequence}",
                'status' => 'completed',
            ]);
            $request = PdfSigningRequest::query()->create([
                'request_uuid' => (string) Str::uuid(),
                'workflow_id' => $workflow->id,
                'signing_act_id' => $act->id,
                'sequence' => $sequence,
                'request_type' => 'handwritten',
                'assigned_user_id' => $actor->id,
                'signing_policy_version_id' => $policy->id,
                'status' => 'signed',
                'completed_revision_id' => $published->id,
            ]);
            $operation = PdfSigningOperation::query()->create([
                'operation_uuid' => (string) Str::uuid(),
                'idempotency_key' => "document-hold-{$sequence}",
                'idempotency_scope_key' => "document-hold-{$sequence}",
                'scope_type' => 'request',
                'actor_user_id' => $actor->id,
                'document_id' => $document->id,
                'workflow_id' => $workflow->id,
                'request_id' => $request->id,
                'action' => 'fill_signature_field',
                'input_fingerprint' => str_repeat((string) $sequence, 64),
                'operation_input_manifest_hash' => str_repeat((string) ($sequence + 2), 64),
                'signing_policy_version_id' => $policy->id,
                'policy_hash' => $policy->policy_hash,
                'result_revision_id' => $published->id,
                'result_revision_uuid' => $published->revision_uuid,
                'result_sha256' => $published->sha256_hash,
                'result_size' => $published->file_size,
                'result_retirement_authorization_hash' => str_repeat('a', 64),
                'result_retirement_authorization_manifest' => ['sequence' => $sequence],
                'result_retirement_not_before' => now()->subMinute(),
                'result_retirement_authorized_at' => now()->subMinute(),
                'result_retirement_authorization_expires_at' => now()->addMinutes(5),
                'state' => 'completed',
                'stage' => 'done',
                'audit_context' => [],
                'audit_context_hash' => str_repeat('b', 64),
            ]);
            $preexisting = $sequence === 2 ? 1 : 0;
            $execution = PdfJavaSigningExecution::query()->create([
                'operation_uuid' => $operation->operation_uuid,
                'operation_input_manifest_hash' => $operation->operation_input_manifest_hash,
                'input_fingerprint' => $operation->input_fingerprint,
                'policy_hash' => $policy->policy_hash,
                'authorized_lease_epoch' => 1,
                'claimed_at' => now()->subDays(8),
                'completed_at' => now()->subDays(8),
                'terminal_at' => now()->subDays(8),
                'state' => 'completed',
                'result_path' => "/java/results/{$operation->operation_uuid}.pdf",
                'result_sha256' => $published->sha256_hash,
                'result_size' => $published->file_size,
                'result_integrity_state' => 'available',
                'retention_until' => now()->addDays(7),
                'retirement_phase' => 'none',
                'evidence_hold_mask' => $preexisting,
                'evidence_hold_state' => $preexisting === 0 ? 'none' : 'active',
            ]);
            $bytes = "document-hold-appearance-{$sequence}";
            $path = "workflow/appearances/document-hold-{$sequence}.png";
            Storage::disk('pdf')->put($path, $bytes);
            $appearance = PdfSignatureAppearanceArtifact::query()->create([
                'appearance_uuid' => (string) Str::uuid(),
                'request_id' => $request->id,
                'created_by_id' => $actor->id,
                'artifact_type' => 'handwriting',
                'canonical_image_sha256' => hash('sha256', $bytes),
                'appearance_manifest_hash' => str_repeat((string) ($sequence + 5), 64),
                'slot_manifest' => [],
                'width' => 10,
                'height' => 10,
                'crop_box' => [],
                'renderer_version' => 'test',
                'state' => 'consumed',
                'evidence_hold_mask' => $preexisting,
                'evidence_hold_state' => $preexisting === 0 ? 'none' : 'active',
                'claimed_by_operation_id' => $operation->id,
                'retention_until' => $sequence === 1 ? now()->subMinute() : now()->addDay(),
                'file_path' => $path,
            ]);
            $operations[] = $operation;
            $executions[] = $execution;
            $appearances[] = $appearance;
        }

        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSigningRetirementEvidence')
            ->andReturnUsing(fn (
                string $operationUuid,
                int $epoch,
                string $phase,
                string $sha256,
                int $size,
            ): array => [
                'operationUuid' => $operationUuid,
                'retirementEpoch' => $epoch,
                'retirementPhase' => $phase,
                'expectedSha256' => $sha256,
                'expectedSize' => $size,
                'canonicalPresent' => $phase === 'none',
                'stagedPresent' => $phase !== 'none',
                'state' => $phase === 'none' ? 'canonical' : 'staged',
            ]);
        $this->app->instance(PdfRendererClient::class, $renderer);

        return [$actor, $document->refresh(), $operations, $executions, $appearances];
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }
}

<?php

namespace Tests\Feature\Pdf;

use App\Jobs\ExecutePdfSigningOperation;
use App\Jobs\ExecutePdfWorkflowControlOperation;
use App\Models\AuditLog;
use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfJavaSigningExecution;
use App\Models\PdfOperationOutbox;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningAct;
use App\Models\PdfSigningChallenge;
use App\Models\PdfSigningField;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningPolicyVersion;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningSlot;
use App\Models\PdfSigningWorkflow;
use App\Models\PdfSourceUpload;
use App\Models\User;
use App\Services\Pdf\CancelPdfWorkflowService;
use App\Services\Pdf\CanonicalJson;
use App\Services\Pdf\PdfImmutableFileStore;
use App\Services\Pdf\PdfOperationOutboxDispatcher;
use App\Services\Pdf\PdfRendererClient;
use App\Services\Pdf\PdfRendererHttpException;
use App\Services\Pdf\PdfRevisionService;
use App\Services\Pdf\PdfSigningOperationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PdfHandwrittenSigningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_source_becomes_a_prepared_persistent_three_person_workflow(): void
    {
        Storage::fake('pdf');
        // Claiming an operation now queues its worker immediately; fake the queue so
        // this test keeps driving the outbox and the worker itself.
        Queue::fake();
        config(['pdf_service.enabled' => true, 'pdf_service.organization_scope' => 'lims-test']);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $sourceInspection = $this->inspection([]);
        $renderer->shouldReceive('inspectSignaturePdf')->once()->andReturn($sourceInspection);
        $renderer->shouldReceive('finalizeUnsignedPdf')->once()->andReturn('%PDF-1.7 source');
        $renderer->shouldReceive('prepareSignatureFields')->once()->withArgs(function (string $path, array $plan): bool {
            $this->assertFileExists($path);
            $this->assertCount(3, $plan);
            $deferred = collect($plan)->mapWithKeys(
                static fn (array $field): array => [$field['fieldName'] => $field['deferred']],
            );
            $this->assertFalse($deferred['lims_inspector_g1']);
            $this->assertFalse($deferred['lims_reviewer_g1']);
            $this->assertFalse($deferred['lims_issuer_g1']);

            return true;
        })->andReturn('%PDF-1.7 prepared immutable revision');

        $preparedFields = $this->preparedFieldInspections();
        $renderer->shouldReceive('inspectSignatureBytes')->times(4)->andReturnUsing(
            fn (string $bytes): array => array_merge(
                $this->inspection(str_contains($bytes, 'prepared') ? $preparedFields : []),
                ['sha256' => hash('sha256', $bytes)],
            ),
        );
        $this->app->instance(PdfRendererClient::class, $renderer);

        $actor = $this->userWithPermissions([
            'pdf.workflow.create',
            'pdf.workflow.read',
            'pdf.request.read',
            'pdf.revision.download',
        ]);
        $inspector = User::factory()->create();
        $reviewer = User::factory()->create();
        $issuer = User::factory()->create();
        Sanctum::actingAs($actor);

        $inspect = $this->post('/api/pdf/signing-sources/inspect', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 source'),
        ])->assertCreated();
        $sourceUuid = $inspect->json('data.source_uuid');
        $this->assertNotEmpty($sourceUuid);
        $this->assertSame('inspected', PdfSourceUpload::query()->sole()->status);

        $confirm = $this->postJson("/api/pdf/signing-sources/{$sourceUuid}/confirm", [
            'report_number' => "  xdp20260001\u{3000}",
        ])->assertCreated();
        $this->assertSame('XDP20260001', $confirm->json('data.normalized_report_number'));
        $this->assertSame('lims-test', PdfDocument::query()->sole()->organization_scope);

        $finalized = $this->withHeader('Idempotency-Key', 'finalize-main-workflow-0001')
            ->postJson("/api/pdf/signing-sources/{$sourceUuid}/finalize")
            ->assertAccepted();
        $this->withHeader('Idempotency-Key', 'finalize-main-workflow-0001')
            ->postJson("/api/pdf/signing-sources/{$sourceUuid}/finalize")
            ->assertAccepted()
            ->assertJsonPath('data.operation_uuid', $finalized->json('data.operation_uuid'));
        $this->dispatchAndExecuteControlOperation($finalized->json('data.operation_uuid'), $renderer);
        $revisionUuid = PdfSigningOperation::query()
            ->where('operation_uuid', $finalized->json('data.operation_uuid'))
            ->value('result_revision_uuid');
        $this->assertSame('finalized_unsigned', PdfFile::query()->where('revision_uuid', $revisionUuid)->value('revision_role'));

        $policy = $this->policy();
        $create = $this->withHeader('Idempotency-Key', 'workflow-main-create-0001')
            ->postJson('/api/pdf/signing-workflows', [
                'planning_revision_uuid' => $revisionUuid,
                'signing_policy_version_uuid' => $policy->version_uuid,
                'assignments' => [
                    'inspector' => $inspector->id,
                    'reviewer' => $reviewer->id,
                    'issuer' => $issuer->id,
                ],
                'placements' => $this->placements(),
            ])->assertCreated();
        $this->withHeader('Idempotency-Key', 'workflow-main-create-0001')
            ->postJson('/api/pdf/signing-workflows', [
                'planning_revision_uuid' => $revisionUuid,
                'signing_policy_version_uuid' => $policy->version_uuid,
                'assignments' => [
                    'inspector' => $inspector->id,
                    'reviewer' => $reviewer->id,
                    'issuer' => $issuer->id,
                ],
                'placements' => $this->placements(),
            ])->assertCreated()
            ->assertJsonPath('data.workflow_uuid', $create->json('data.workflow_uuid'));
        $workflowUuid = $create->json('data.workflow_uuid');
        $this->assertCount(3, $create->json('data.requests'));
        $this->assertCount(3, $create->json('data.fields'));
        $this->assertSame(1, PdfDocument::query()->sole()->active_workflow_id);
        $this->assertSame(3, PdfSigningRequest::query()->count());
        $this->assertSame(3, PdfSigningField::query()->count());
        $this->assertSame([
            'x' => '0.080000',
            'y' => '0.700000',
            'width' => '0.240000',
            'height' => '0.080000',
        ], PdfSigningField::query()
            ->where('field_name', 'lims_inspector_g1')
            ->firstOrFail()
            ->slots()
            ->sole()
            ->normalized_rect);

        $prepareOperation = $this->withHeader('Idempotency-Key', 'prepare-main-workflow-0001')
            ->postJson("/api/pdf/signing-workflows/{$workflowUuid}/prepare")
            ->assertAccepted();
        $this->withHeader('Idempotency-Key', 'prepare-main-workflow-0001')
            ->postJson("/api/pdf/signing-workflows/{$workflowUuid}/prepare")
            ->assertAccepted()
            ->assertJsonPath('data.operation_uuid', $prepareOperation->json('data.operation_uuid'));
        $this->dispatchAndExecuteControlOperation($prepareOperation->json('data.operation_uuid'), $renderer);
        $prepared = $this->getJson("/api/pdf/signing-workflows/{$workflowUuid}")->assertOk();
        $this->assertSame('ready', $prepared->json('data.status'));
        $this->assertSame('available', $prepared->json('data.requests.0.status'));
        $this->assertSame('pending', $prepared->json('data.requests.1.status'));
        $this->assertSame(2, PdfFile::query()->count());
        $this->assertSame('prepared', PdfFile::query()->orderByDesc('revision_number')->value('revision_role'));
        $this->assertSame(3, PdfSigningField::query()->where('status', 'prepared')->count());
        $this->assertSame(3, PdfSigningField::query()->whereNotNull('prepared_object_ref')->count());
        $this->assertSame(3, PdfSigningSlot::query()->whereNotNull('prepared_widget_object_ref')->count());
        $this->assertSame(3, PdfSigningSlot::query()->whereNotNull('prepared_appearance_object_refs')->count());

        $this->grantPermissions($inspector, [
            'pdf.request.read',
            'pdf.request.sign_assigned',
            'pdf.organization_key.use',
        ]);
        Sanctum::actingAs($inspector);
        $requestUuid = $prepared->json('data.requests.0.request_uuid');
        $appearance = $this->post("/api/pdf/signing-requests/{$requestUuid}/appearances", [
            'appearance' => UploadedFile::fake()->createWithContent('signature.png', $this->signaturePng()),
        ])->assertCreated();
        $this->assertSame('available', PdfSignatureAppearanceArtifact::query()->sole()->state);
        $challenge = $this->postJson("/api/pdf/signing-requests/{$requestUuid}/challenge", [
            'appearance_uuid' => $appearance->json('data.appearance_uuid'),
            'current_password' => 'password',
        ])->assertCreated();
        $this->assertSame($prepared->json('data.requests.0.expected_source_sha256'), $challenge->json('data.source_sha256'));
        $this->assertSame(1, PdfSigningChallenge::query()->count());

        $idempotencyKey = 'sign-test-operation-0001';
        $operation = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/pdf/signing-requests/{$requestUuid}/sign", [
                'challenge_uuid' => $challenge->json('data.challenge_uuid'),
            ])->assertAccepted();
        $operationUuid = $operation->json('data.operation_uuid');
        $this->assertSame('claimed', $operation->json('data.state'));
        $this->assertSame('awaiting_dispatch', $operation->json('data.stage'));
        $this->assertSame(1, PdfSigningOperation::query()->where('action', 'fill_signature_field')->count());
        $this->assertSame('pending', PdfOperationOutbox::query()->where('operation_id', PdfSigningOperation::query()->where('operation_uuid', $operationUuid)->value('id'))->value('state'));

        Queue::fake();
        $this->assertSame(1, app(PdfOperationOutboxDispatcher::class)->dispatchPending());
        Queue::assertPushed(ExecutePdfSigningOperation::class, fn (ExecutePdfSigningOperation $job): bool => $job->operationUuid === $operationUuid);
        $this->assertSame('dispatched', PdfOperationOutbox::query()->where('operation_id', PdfSigningOperation::query()->where('operation_uuid', $operationUuid)->value('id'))->value('state'));
        $this->assertNotNull(PdfSigningChallenge::query()->sole()->consumed_at);
        $this->assertSame('claimed', PdfSignatureAppearanceArtifact::query()->sole()->state);
        $this->assertSame('signing', PdfSigningRequest::query()->where('request_uuid', $requestUuid)->value('status'));
        $this->assertSame(PdfSigningOperation::query()->where('operation_uuid', $operationUuid)->value('id'), PdfDocument::query()->sole()->active_operation_id);

        $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/pdf/signing-requests/{$requestUuid}/sign", [
                'challenge_uuid' => $challenge->json('data.challenge_uuid'),
            ])->assertAccepted()
            ->assertJsonPath('data.operation_uuid', $operationUuid);
        $this->assertSame(1, PdfSigningOperation::query()->where('action', 'fill_signature_field')->count());

        $signedBytes = '%PDF-1.7 incrementally signed inspector revision';
        $signedSha256 = hash('sha256', $signedBytes);
        $executionRenderer = Mockery::mock(PdfRendererClient::class);
        $duplicateDeliveryObserved = false;
        $executionRenderer->shouldReceive('submitSigningExecution')->once()
            ->andReturnUsing(function () use (
                $operationUuid,
                $signedSha256,
                $signedBytes,
                $executionRenderer,
                &$duplicateDeliveryObserved,
            ): array {
                $duplicateDeliveryObserved = true;
                (new ExecutePdfSigningOperation($operationUuid))->handle(
                    $executionRenderer,
                    app(PdfImmutableFileStore::class),
                    app(PdfRevisionService::class),
                );
                $frozen = PdfSigningOperation::query()->where('operation_uuid', $operationUuid)->firstOrFail();
                PdfJavaSigningExecution::query()->create([
                    'operation_uuid' => $operationUuid,
                    'operation_input_manifest_hash' => $frozen->operation_input_manifest_hash,
                    'input_fingerprint' => $frozen->input_fingerprint,
                    'policy_hash' => $frozen->policy_hash,
                    'attempt_number' => 1,
                    'attempt_count' => 1,
                    'max_attempts' => 3,
                    'state' => 'completed',
                    'authorized_lease_epoch' => $frozen->lease_epoch,
                    'lock_version' => 4,
                    'claimed_at' => now(),
                    'execution_started_at' => now(),
                    'private_key_started_at' => now(),
                    'execution_deadline_at' => now()->addMinute(),
                    'completed_at' => now(),
                    'terminal_at' => now(),
                    'result_path' => '/java-results/'.$operationUuid.'/result.pdf',
                    'result_sha256' => $signedSha256,
                    'result_size' => strlen($signedBytes),
                    'result_file_key' => 'test-inode',
                    'validation_report_hash' => str_repeat('d', 64),
                    'result_integrity_state' => 'missing',
                    'retirement_phase' => 'none',
                    'retention_until' => now()->addDays(7),
                ]);

                return [
                    'state' => 'completed',
                    'resultSha256' => $signedSha256,
                    'resultSize' => strlen($signedBytes),
                ];
            });
        $statusCalls = 0;
        $executionRenderer->shouldReceive('signingExecutionStatus')->once()->andReturnUsing(
            function () use (&$statusCalls, $signedSha256, $signedBytes): array {
                $statusCalls++;
                if ($statusCalls === 1) {
                    throw new PdfRendererHttpException(404, '');
                }

                return [
                    'state' => 'completed',
                    'resultSha256' => $signedSha256,
                    'resultSize' => strlen($signedBytes),
                ];
            },
        );
        $resultCalls = 0;
        $executionRenderer->shouldReceive('signingExecutionResult')->once()->andReturnUsing(
            function () use (&$resultCalls): array {
                $resultCalls++;
                throw new PdfRendererHttpException(503, 'RESULT_BYTES_MISSING');
            },
        );
        $executionRenderer->shouldReceive('inspectSignatureBytes')->twice()->andReturn(array_merge(
            $this->inspection([[
                'fieldName' => 'lims_inspector_g1',
                'signed' => true,
                'selfOnlyLock' => true,
                'widgetCount' => 1,
            ]]),
            ['signatureCount' => 1, 'docMdpPermission' => 2],
        ));
        $executionRenderer->shouldReceive('verifySignatureBytes')->twice()->andReturn([
            'documentCurrentState' => 'valid',
            'docMdpPermission' => 2,
            'signatures' => [['documentCurrentState' => 'valid']],
            'error' => null,
        ]);
        $operationBeforePromotion = PdfSigningOperation::query()
            ->where('operation_uuid', $operationUuid)
            ->sole();
        $priorStagingPath = "workflow/staging/{$operationUuid}/{$operationBeforePromotion->lease_epoch}/candidate.pdf";
        app(PdfImmutableFileStore::class)->putBytes($signedBytes, $priorStagingPath);
        DB::unprepared(<<<SQL
            CREATE TRIGGER reject_promoted_revision
            BEFORE INSERT ON pdf_files
            WHEN NEW.revision_uuid = '{$operationBeforePromotion->result_revision_uuid}'
            BEGIN
                SELECT RAISE(ABORT, 'injected transaction B failure');
            END
            SQL);
        $transactionFailureObserved = false;
        try {
            (new ExecutePdfSigningOperation($operationUuid))->handle(
                $executionRenderer,
                app(PdfImmutableFileStore::class),
                app(PdfRevisionService::class),
            );
        } catch (\Throwable $exception) {
            $transactionFailureObserved = true;
            $this->assertStringContainsString('injected transaction B failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_promoted_revision');
        }
        $this->assertTrue($transactionFailureObserved, 'Transaction B fault injection did not fire.');
        $promoting = PdfSigningOperation::query()->where('operation_uuid', $operationUuid)->sole();
        $this->assertSame('promoted', $promoting->state);
        $this->assertSame('committing', $promoting->stage);
        $finalPath = "workflow/revisions/{$promoting->result_revision_uuid}/{$promoting->operation_uuid}/{$promoting->lease_epoch}/document.pdf";
        Storage::disk('pdf')->assertExists($finalPath);
        $this->assertSame($finalPath, $promoting->promoted_file_path);
        Storage::disk('pdf')->assertMissing(
            "workflow/staging/{$promoting->operation_uuid}/{$promoting->lease_epoch}/candidate.pdf",
        );
        (new ExecutePdfSigningOperation($operationUuid))->handle(
            $executionRenderer,
            app(PdfImmutableFileStore::class),
            app(PdfRevisionService::class),
        );
        $this->assertTrue($duplicateDeliveryObserved);
        $this->assertSame(1, $statusCalls);
        $this->assertSame(1, $resultCalls);
        $this->assertSame('completed', PdfSigningOperation::query()->where('operation_uuid', $operationUuid)->value('state'));
        $this->assertSame($finalPath, PdfSigningOperation::query()->where('operation_uuid', $operationUuid)->value('promoted_file_path'));
        $this->assertSame('signed', PdfSigningRequest::query()->where('sequence', 1)->value('status'));
        $this->assertSame('available', PdfSigningRequest::query()->where('sequence', 2)->value('status'));
        $this->assertSame('pending', PdfSigningRequest::query()->where('sequence', 3)->value('status'));
        $this->assertSame(3, PdfFile::query()->count());
        $this->assertNull(PdfDocument::query()->sole()->active_operation_id);
        $this->assertDatabaseHas('pdf_signing_operation_events', [
            'operation_id' => $promoting->id,
            'event_type' => 'DOWNSTREAM_COPY_RECOVERED_AFTER_JAVA_RESULT_INTEGRITY_FAILURE',
        ]);

        $this->grantPermissions($reviewer, [
            'pdf.request.read',
            'pdf.request.sign_assigned',
            'pdf.organization_key.use',
        ]);
        Sanctum::actingAs($reviewer);
        $reviewRequestUuid = PdfSigningRequest::query()->where('sequence', 2)->value('request_uuid');
        $reviewAppearance = $this->post("/api/pdf/signing-requests/{$reviewRequestUuid}/appearances", [
            'appearance' => UploadedFile::fake()->createWithContent('review-signature.png', $this->signaturePng()),
        ])->assertCreated();
        $reviewChallenge = $this->postJson("/api/pdf/signing-requests/{$reviewRequestUuid}/challenge", [
            'appearance_uuid' => $reviewAppearance->json('data.appearance_uuid'),
            'current_password' => 'password',
        ])->assertCreated();
        $reviewOperation = $this->withHeader('Idempotency-Key', 'sign-test-operation-0002')
            ->postJson("/api/pdf/signing-requests/{$reviewRequestUuid}/sign", [
                'challenge_uuid' => $reviewChallenge->json('data.challenge_uuid'),
            ])->assertAccepted();

        $this->grantPermissions($actor, ['pdf.workflow.cancel']);
        Sanctum::actingAs($actor);
        $this->postJson("/api/pdf/signing-workflows/{$workflowUuid}/cancel", [
            'reason_code' => 'OPERATOR_CANCELLED',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancelled', PdfSigningOperation::query()
            ->where('operation_uuid', $reviewOperation->json('data.operation_uuid'))
            ->value('state'));
        $this->assertSame('cancelled', PdfSigningRequest::query()->where('sequence', 2)->value('status'));
        $this->assertSame('cancelled', PdfSigningRequest::query()->where('sequence', 3)->value('status'));
        $signedFieldId = PdfSigningField::query()
            ->where('signing_act_id', PdfSigningAct::query()->where('semantic_role', 'inspector')->value('id'))
            ->value('id');
        $this->assertSame('signed', PdfSigningField::query()->findOrFail($signedFieldId)->status);
        $this->assertSame('rendered', PdfSigningSlot::query()->where('field_id', $signedFieldId)->value('status'));
        $this->assertSame(2, PdfSigningSlot::query()->where('status', 'cancelled')->count());
        $this->assertSame('cancelled', PdfDocument::query()->sole()->status);
        $this->assertNull(PdfDocument::query()->sole()->active_workflow_id);
        $this->assertSame('cancelled', PdfOperationOutbox::query()->orderByDesc('id')->value('state'));
    }

    public function test_workflow_cancel_without_active_operation_terminalizes_all_unused_scope_rows(): void
    {
        [$actor, $inspector, $workflowUuid, $requestUuid] = $this->preparedWorkflowForCancellation();

        $this->grantPermissions($inspector, [
            'pdf.request.sign_assigned',
            'pdf.organization_key.use',
        ]);
        Sanctum::actingAs($inspector);
        $appearance = $this->post("/api/pdf/signing-requests/{$requestUuid}/appearances", [
            'appearance' => UploadedFile::fake()->createWithContent('signature.png', $this->signaturePng()),
        ])->assertCreated();
        $this->postJson("/api/pdf/signing-requests/{$requestUuid}/challenge", [
            'appearance_uuid' => $appearance->json('data.appearance_uuid'),
            'current_password' => 'password',
        ])->assertCreated();
        PdfSignatureAppearanceArtifact::query()->update(['retention_until' => now()->addHour()]);

        Sanctum::actingAs($actor);
        $this->postJson("/api/pdf/signing-workflows/{$workflowUuid}/cancel", [
            'reason_code' => 'PLANNER_CANCELLED',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(3, PdfSigningRequest::query()->where('status', 'cancelled')->count());
        $this->assertSame(3, PdfSigningField::query()->where('status', 'cancelled')->count());
        $this->assertSame(3, PdfSigningSlot::query()->where('status', 'cancelled')->count());
        $this->assertSame(3, PdfSigningAct::query()->where('status', 'cancelled')->count());
        $this->assertNotNull(PdfSigningChallenge::query()->sole()->cancelled_at);
        $unusedAppearance = PdfSignatureAppearanceArtifact::query()->sole();
        $this->assertSame('available', $unusedAppearance->state);
        $this->assertTrue($unusedAppearance->retention_until->isAfter(now()->addHours(23)));
        $this->assertNull(PdfDocument::query()->sole()->active_workflow_id);
        $this->assertNull(PdfDocument::query()->sole()->active_operation_id);
        $this->assertSame('cancelled', PdfDocument::query()->sole()->status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pdf.workflow.cancelled',
            'module' => 'pdf.workflow',
            'actor_user_id' => $actor->id,
        ]);

        $auditCount = AuditLog::query()->where('action', 'pdf.workflow.cancelled')->count();
        $this->postJson("/api/pdf/signing-workflows/{$workflowUuid}/cancel", [
            'reason_code' => 'PLANNER_CANCELLED',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame($auditCount, AuditLog::query()->where('action', 'pdf.workflow.cancelled')->count());
    }

    public function test_workflow_cancel_delegates_active_operation_arbitration_and_standard_sign_route(): void
    {
        [$actor, $inspector, $workflowUuid, $requestUuid] = $this->preparedWorkflowForCancellation();
        $operation = $this->claimCancellationTestOperation($inspector, $requestUuid);

        Sanctum::actingAs($actor);
        $this->postJson("/api/pdf/signing-workflows/{$workflowUuid}/cancel", [
            'reason_code' => 'PLANNER_CANCELLED',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $operation->refresh()->state);
        $this->assertSame('done', $operation->stage);
        $this->assertSame('cancelled', PdfOperationOutbox::query()->where('operation_id', $operation->id)->value('state'));
        $this->assertSame('quarantined', PdfSignatureAppearanceArtifact::query()->sole()->state);
        $this->assertSame(3, PdfSigningField::query()->where('status', 'cancelled')->count());
        $this->assertNull(PdfDocument::query()->sole()->active_workflow_id);
        $this->assertNull(PdfDocument::query()->sole()->active_operation_id);
    }

    public function test_workflow_cancel_loses_after_private_key_boundary_without_clearing_pointers(): void
    {
        [$actor, $inspector, $workflowUuid, $requestUuid] = $this->preparedWorkflowForCancellation();
        $operation = $this->claimCancellationTestOperation($inspector, $requestUuid);
        PdfJavaSigningExecution::query()->create([
            'operation_uuid' => $operation->operation_uuid,
            'operation_input_manifest_hash' => $operation->operation_input_manifest_hash,
            'input_fingerprint' => $operation->input_fingerprint,
            'policy_hash' => $operation->policy_hash,
            'attempt_number' => 1,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'state' => 'executing',
            'authorized_lease_epoch' => $operation->lease_epoch,
            'lock_version' => 2,
            'claimed_at' => now(),
            'execution_started_at' => now(),
            'private_key_started_at' => now(),
            'execution_deadline_at' => now()->addMinute(),
            'result_integrity_state' => 'not_applicable',
            'retirement_phase' => 'none',
        ]);

        Sanctum::actingAs($actor);
        $this->postJson("/api/pdf/signing-workflows/{$workflowUuid}/cancel", [
            'reason_code' => 'PLANNER_CANCELLED',
        ])->assertConflict()->assertSee('SIGNING_IRREVERSIBLE_IN_PROGRESS');

        $document = PdfDocument::query()->sole();
        $this->assertSame($operation->id, $document->active_operation_id);
        $this->assertNotNull($document->active_workflow_id);
        $this->assertSame('claimed', $operation->refresh()->state);
        $this->assertSame('signing', PdfSigningRequest::query()->where('request_uuid', $requestUuid)->value('status'));
        $this->assertSame('signing', PdfSigningWorkflow::query()->whereKey($operation->workflow_id)->value('status'));
        $this->assertSame('pending', PdfOperationOutbox::query()->where('operation_id', $operation->id)->value('state'));
    }

    public function test_prepare_cancel_race_leaves_generated_revision_abandoned_and_workflow_cancelled(): void
    {
        [, , $workflowUuid] = $this->preparedWorkflowForCancellation(cancelDuringPrepare: true);

        $workflow = PdfSigningWorkflow::query()->where('workflow_uuid', $workflowUuid)->sole();
        $this->assertSame('cancelled', $workflow->status);
        $this->assertNull($workflow->prepared_revision_id);
        $this->assertSame(0, PdfFile::query()->where('revision_role', 'prepared')->count());
        $this->assertSame('cancelled', PdfSigningOperation::query()->where('action', 'prepare_fields')->value('state'));
        $this->assertSame(3, PdfSigningField::query()->where('status', 'cancelled')->count());
        $this->assertNull(PdfDocument::query()->sole()->active_workflow_id);
        $this->assertNull(PdfDocument::query()->sole()->active_operation_id);
    }

    public function test_request_rejection_without_active_operation_terminalizes_the_same_workflow_scope(): void
    {
        [, $inspector, , $requestUuid] = $this->preparedWorkflowForCancellation();
        $this->grantPermissions($inspector, [
            'pdf.request.reject',
            'pdf.request.sign_assigned',
            'pdf.organization_key.use',
        ]);
        Sanctum::actingAs($inspector);
        $appearance = $this->post("/api/pdf/signing-requests/{$requestUuid}/appearances", [
            'appearance' => UploadedFile::fake()->createWithContent('signature.png', $this->signaturePng()),
        ])->assertCreated();
        $this->postJson("/api/pdf/signing-requests/{$requestUuid}/challenge", [
            'appearance_uuid' => $appearance->json('data.appearance_uuid'),
            'current_password' => 'password',
        ])->assertCreated();

        $this->postJson("/api/pdf/signing-requests/{$requestUuid}/reject", [
            'reason_code' => 'CONTENT_REQUIRES_CORRECTION',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame('rejected', PdfSigningRequest::query()->where('request_uuid', $requestUuid)->value('status'));
        $this->assertSame(2, PdfSigningRequest::query()->where('status', 'cancelled')->count());
        $this->assertSame(3, PdfSigningField::query()->where('status', 'cancelled')->count());
        $this->assertSame(3, PdfSigningSlot::query()->where('status', 'cancelled')->count());
        $this->assertSame(3, PdfSigningAct::query()->where('status', 'cancelled')->count());
        $this->assertNotNull(PdfSigningChallenge::query()->sole()->cancelled_at);
        $this->assertSame('rejected', PdfSigningWorkflow::query()->sole()->status);
        $this->assertNull(PdfDocument::query()->sole()->active_workflow_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pdf.workflow.rejected',
            'actor_user_id' => $inspector->id,
        ]);
    }

    public function test_finalize_operation_replays_promoted_bytes_after_transaction_b_failure(): void
    {
        Storage::fake('pdf');
        // Claiming an operation now queues its worker immediately; fake the queue so
        // this test keeps driving the outbox and the worker itself.
        Queue::fake();
        config(['pdf_service.enabled' => true, 'pdf_service.organization_scope' => 'lims-test']);
        $sourceBytes = '%PDF control finalization crash fixture';
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSignaturePdf')->once()->andReturn($this->inspection([]));
        $renderer->shouldReceive('finalizeUnsignedPdf')->once()->andReturn($sourceBytes);
        $renderer->shouldReceive('inspectSignatureBytes')->times(3)->andReturnUsing(
            fn (string $bytes): array => array_merge(
                $this->inspection([]),
                ['sha256' => hash('sha256', $bytes)],
            ),
        );
        $this->app->instance(PdfRendererClient::class, $renderer);
        $actor = $this->userWithPermissions(['pdf.workflow.create']);
        Sanctum::actingAs($actor);
        $source = $this->post('/api/pdf/signing-sources/inspect', [
            'pdf_file' => UploadedFile::fake()->createWithContent('crash.pdf', $sourceBytes),
        ])->assertCreated();
        $sourceUuid = $source->json('data.source_uuid');
        $this->postJson("/api/pdf/signing-sources/{$sourceUuid}/confirm", [
            'report_number' => 'CONTROL-CRASH-001',
        ])->assertCreated();
        $claimed = $this->withHeader('Idempotency-Key', 'finalize-control-crash-0001')
            ->postJson("/api/pdf/signing-sources/{$sourceUuid}/finalize")
            ->assertAccepted();
        $operation = PdfSigningOperation::query()
            ->where('operation_uuid', $claimed->json('data.operation_uuid'))
            ->sole();
        DB::unprepared(<<<SQL
            CREATE TRIGGER reject_control_revision
            BEFORE INSERT ON pdf_files
            WHEN NEW.revision_uuid = '{$operation->result_revision_uuid}'
            BEGIN
                SELECT RAISE(ABORT, 'injected control transaction B failure');
            END
            SQL);
        try {
            $this->dispatchAndExecuteControlOperation($operation->operation_uuid, $renderer);
            $this->fail('Control transaction B fault injection did not fire.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('injected control transaction B failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_control_revision');
        }

        $operation->refresh();
        $this->assertSame('promoted', $operation->state);
        $this->assertSame('committing', $operation->stage);
        Storage::disk('pdf')->assertExists($operation->promoted_file_path);
        $this->assertSame(0, PdfFile::query()->where('revision_uuid', $operation->result_revision_uuid)->count());
        $operation->update(['lease_expires_at' => now()->subSecond()]);
        $this->assertSame(1, app(PdfSigningOperationReconciler::class)->sweep());
        $this->assertSame('pending', PdfOperationOutbox::query()->where('operation_id', $operation->id)->value('state'));

        $this->dispatchAndExecuteControlOperation($operation->operation_uuid, $renderer);
        $this->assertSame('completed', $operation->refresh()->state);
        $this->assertSame(1, PdfFile::query()->where('revision_uuid', $operation->result_revision_uuid)->count());
        $this->assertNull(PdfDocument::query()->sole()->active_operation_id);
    }

    public function test_duplicate_report_number_and_signed_input_fail_closed(): void
    {
        Storage::fake('pdf');
        config(['pdf_service.enabled' => true, 'pdf_service.organization_scope' => 'lims-test']);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSignaturePdf')->times(3)->andReturn(
            $this->inspection([]),
            array_merge($this->inspection([]), ['signatureCount' => 1, 'docMdpPermission' => 2]),
            $this->inspection([]),
        );
        $this->app->instance(PdfRendererClient::class, $renderer);
        $actor = $this->userWithPermissions(['pdf.workflow.create']);
        Sanctum::actingAs($actor);

        $first = $this->post('/api/pdf/signing-sources/inspect', [
            'pdf_file' => UploadedFile::fake()->createWithContent('first.pdf', '%PDF first'),
        ])->assertCreated();
        $this->postJson('/api/pdf/signing-sources/'.$first->json('data.source_uuid').'/confirm', [
            'report_number' => 'abc-1',
        ])->assertCreated();

        $second = $this->post('/api/pdf/signing-sources/inspect', [
            'pdf_file' => UploadedFile::fake()->createWithContent('signed.pdf', '%PDF signed'),
        ]);
        $second->assertStatus(422);
        $duplicate = $this->post('/api/pdf/signing-sources/inspect', [
            'pdf_file' => UploadedFile::fake()->createWithContent('duplicate-report.pdf', '%PDF duplicate report'),
        ])->assertCreated();
        $this->postJson('/api/pdf/signing-sources/'.$duplicate->json('data.source_uuid').'/confirm', [
            'report_number' => "\u{3000}ABC-1 ",
        ])->assertConflict();
        $this->assertSame(1, PdfDocument::query()->count());
        $this->assertSame(2, PdfSourceUpload::query()->count());
        $this->assertCount(2, Storage::disk('pdf')->allFiles('workflow/sources'));
    }

    /**
     * Identical bytes used to be rejected globally and forever, so one abandoned
     * upload permanently blocked that file for every user and every report.
     * Business identity belongs to the report number, which is still enforced.
     */
    public function test_identical_bytes_can_be_uploaded_again_under_a_different_report_number(): void
    {
        Storage::fake('pdf');
        Queue::fake();
        config(['pdf_service.enabled' => true, 'pdf_service.organization_scope' => 'lims-test']);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSignaturePdf')->times(3)->andReturn($this->inspection([]));
        $this->app->instance(PdfRendererClient::class, $renderer);
        $actor = $this->userWithPermissions(['pdf.workflow.create']);
        Sanctum::actingAs($actor);
        $bytes = '%PDF identical bytes';

        $first = $this->post('/api/pdf/signing-sources/inspect', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report.pdf', $bytes),
        ])->assertCreated();
        $this->postJson('/api/pdf/signing-sources/'.$first->json('data.source_uuid').'/confirm', [
            'report_number' => 'DUP-1',
        ])->assertCreated();

        // Same bytes, second upload: allowed, and a distinct source.
        $second = $this->post('/api/pdf/signing-sources/inspect', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report-again.pdf', $bytes),
        ])->assertCreated();
        $this->assertNotSame($first->json('data.source_uuid'), $second->json('data.source_uuid'));
        $this->assertSame($first->json('data.sha256'), $second->json('data.sha256'));

        // A different report number gives it its own document identity.
        $this->postJson('/api/pdf/signing-sources/'.$second->json('data.source_uuid').'/confirm', [
            'report_number' => 'DUP-2',
        ])->assertCreated();

        // The same report number is still a conflict.
        $third = $this->post('/api/pdf/signing-sources/inspect', [
            'pdf_file' => UploadedFile::fake()->createWithContent('report-third.pdf', $bytes),
        ])->assertCreated();
        $this->postJson('/api/pdf/signing-sources/'.$third->json('data.source_uuid').'/confirm', [
            'report_number' => 'DUP-1',
        ])->assertConflict();

        $this->assertSame(2, PdfDocument::query()->count());
        $this->assertSame(3, PdfSourceUpload::query()->count());
    }

    public function test_only_the_source_owner_can_confirm_or_finalize_an_upload(): void
    {
        Storage::fake('pdf');
        config(['pdf_service.enabled' => true, 'pdf_service.organization_scope' => 'lims-test']);
        $renderer = Mockery::mock(PdfRendererClient::class);
        $renderer->shouldReceive('inspectSignaturePdf')->once()->andReturn($this->inspection([]));
        $this->app->instance(PdfRendererClient::class, $renderer);
        $owner = $this->userWithPermissions(['pdf.workflow.create']);
        $other = $this->userWithPermissions(['pdf.workflow.create']);
        Sanctum::actingAs($owner);
        $source = $this->post('/api/pdf/signing-sources/inspect', [
            'pdf_file' => UploadedFile::fake()->createWithContent('owned.pdf', '%PDF owned source'),
        ])->assertCreated();
        $sourceUuid = $source->json('data.source_uuid');

        Sanctum::actingAs($other);
        $this->postJson("/api/pdf/signing-sources/{$sourceUuid}/confirm", [
            'report_number' => 'OWNED-1',
        ])->assertConflict()->assertSee('PDF_SOURCE_NOT_OWNED');

        Sanctum::actingAs($owner);
        $this->postJson("/api/pdf/signing-sources/{$sourceUuid}/confirm", [
            'report_number' => 'OWNED-1',
        ])->assertCreated();

        Sanctum::actingAs($other);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/pdf/signing-sources/{$sourceUuid}/finalize")
            ->assertConflict()
            ->assertSee('PDF_SOURCE_NOT_FINALIZABLE');
        $this->assertSame(0, PdfSigningOperation::query()->count());
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function inspection(array $fields): array
    {
        return [
            'sha256' => str_repeat('a', 64),
            'pageCount' => 2,
            'encrypted' => false,
            'signatureCount' => 0,
            'docMdpPermission' => null,
            'pages' => [[
                'pageIndex' => 0,
                'cropLowerLeftX' => '0',
                'cropLowerLeftY' => '0',
                'cropWidth' => '595.28',
                'cropHeight' => '841.89',
                'rotation' => 0,
            ]],
            'fields' => $fields,
        ];
    }

    /** @return array{User, User, string, string} */
    private function preparedWorkflowForCancellation(bool $cancelDuringPrepare = false): array
    {
        Storage::fake('pdf');
        // Claiming an operation now queues its worker immediately; fake the queue so
        // these tests keep controlling exactly when the worker runs.
        Queue::fake();
        config(['pdf_service.enabled' => true, 'pdf_service.organization_scope' => 'lims-test']);
        $actor = null;
        $workflowUuid = null;
        $renderer = Mockery::mock(PdfRendererClient::class);
        $sourceInspection = $this->inspection([]);
        $renderer->shouldReceive('inspectSignaturePdf')->once()->andReturn($sourceInspection);
        $renderer->shouldReceive('finalizeUnsignedPdf')->once()->andReturn('%PDF cancellation source');
        $renderer->shouldReceive('prepareSignatureFields')->once()->andReturnUsing(
            function () use (&$actor, &$workflowUuid, $cancelDuringPrepare): string {
                if ($cancelDuringPrepare) {
                    $this->assertInstanceOf(User::class, $actor);
                    $this->assertIsString($workflowUuid);
                    app(CancelPdfWorkflowService::class)->cancel(
                        PdfSigningWorkflow::query()->where('workflow_uuid', $workflowUuid)->sole(),
                        'PREPARE_RACE_CANCELLED',
                        $actor,
                    );
                }

                return '%PDF-1.7 prepared cancellation fixture';
            },
        );
        $preparedFields = $this->preparedFieldInspections();
        $renderer->shouldReceive('inspectSignatureBytes')
            ->times($cancelDuringPrepare ? 2 : 4)
            ->andReturnUsing(fn (string $bytes): array => array_merge(
                $this->inspection(str_contains($bytes, 'prepared') ? $preparedFields : []),
                ['sha256' => hash('sha256', $bytes)],
            ));
        $this->app->instance(PdfRendererClient::class, $renderer);

        $actor = $this->userWithPermissions([
            'pdf.workflow.create',
            'pdf.workflow.cancel',
        ]);
        $inspector = User::factory()->create();
        $reviewer = User::factory()->create();
        $issuer = User::factory()->create();
        Sanctum::actingAs($actor);
        $source = $this->post('/api/pdf/signing-sources/inspect', [
            'pdf_file' => UploadedFile::fake()->createWithContent('cancel.pdf', '%PDF cancellation source'),
        ])->assertCreated();
        $sourceUuid = $source->json('data.source_uuid');
        $this->postJson("/api/pdf/signing-sources/{$sourceUuid}/confirm", [
            'report_number' => 'CANCEL-'.Str::upper(Str::random(8)),
        ])->assertCreated();
        $finalized = $this->withHeader('Idempotency-Key', 'finalize-cancel-'.Str::uuid())
            ->postJson("/api/pdf/signing-sources/{$sourceUuid}/finalize")
            ->assertAccepted();
        $this->dispatchAndExecuteControlOperation($finalized->json('data.operation_uuid'), $renderer);
        $planningRevisionUuid = PdfSigningOperation::query()
            ->where('operation_uuid', $finalized->json('data.operation_uuid'))
            ->value('result_revision_uuid');
        $created = $this->withHeader('Idempotency-Key', 'workflow-cancel-'.Str::uuid())
            ->postJson('/api/pdf/signing-workflows', [
                'planning_revision_uuid' => $planningRevisionUuid,
                'signing_policy_version_uuid' => $this->policy()->version_uuid,
                'assignments' => [
                    'inspector' => $inspector->id,
                    'reviewer' => $reviewer->id,
                    'issuer' => $issuer->id,
                ],
                'placements' => $this->placements(),
            ])->assertCreated();
        $workflowUuid = $created->json('data.workflow_uuid');
        $requestUuid = $created->json('data.requests.0.request_uuid');
        $prepare = $this->withHeader('Idempotency-Key', 'prepare-cancel-'.Str::uuid())
            ->postJson("/api/pdf/signing-workflows/{$workflowUuid}/prepare")
            ->assertAccepted();
        if ($cancelDuringPrepare) {
            try {
                $this->dispatchAndExecuteControlOperation($prepare->json('data.operation_uuid'), $renderer);
                $this->fail('Prepare/cancel race did not stop the control operation.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('lost its worker fence', $exception->getMessage());
            }
        } else {
            $this->dispatchAndExecuteControlOperation($prepare->json('data.operation_uuid'), $renderer);
            $requestUuid = PdfSigningRequest::query()
                ->where('workflow_id', PdfSigningWorkflow::query()->where('workflow_uuid', $workflowUuid)->value('id'))
                ->where('sequence', 1)
                ->value('request_uuid');
        }

        return [$actor, $inspector, $workflowUuid, $requestUuid];
    }

    private function claimCancellationTestOperation(User $inspector, string $requestUuid): PdfSigningOperation
    {
        $this->grantPermissions($inspector, [
            'pdf.request.sign_assigned',
            'pdf.organization_key.use',
        ]);
        Sanctum::actingAs($inspector);
        $appearance = $this->post("/api/pdf/signing-requests/{$requestUuid}/appearances", [
            'appearance' => UploadedFile::fake()->createWithContent('signature.png', $this->signaturePng()),
        ])->assertCreated();
        $challenge = $this->postJson("/api/pdf/signing-requests/{$requestUuid}/challenge", [
            'appearance_uuid' => $appearance->json('data.appearance_uuid'),
            'current_password' => 'password',
        ])->assertCreated();
        $this->withHeader('Idempotency-Key', 'cancel-sign-'.Str::uuid())
            ->postJson("/api/pdf/signing-requests/{$requestUuid}/sign", [
                'challenge_uuid' => $challenge->json('data.challenge_uuid'),
            ])->assertAccepted();

        return PdfSigningOperation::query()->where('action', 'fill_signature_field')->sole();
    }

    private function dispatchAndExecuteControlOperation(
        string $operationUuid,
        PdfRendererClient $renderer,
    ): void {
        Queue::fake();
        $this->assertSame(1, app(PdfOperationOutboxDispatcher::class)->dispatchPending());
        Queue::assertPushed(
            ExecutePdfWorkflowControlOperation::class,
            fn (ExecutePdfWorkflowControlOperation $job): bool => $job->operationUuid === $operationUuid,
        );
        (new ExecutePdfWorkflowControlOperation($operationUuid))->handle(
            $renderer,
            app(PdfImmutableFileStore::class),
            app(PdfRevisionService::class),
        );
    }

    /** @return list<array<string, mixed>> */
    private function preparedFieldInspections(): array
    {
        return collect($this->placements())->values()->map(
            function (array $placement, int $index): array {
                $objectNumber = 20 + ($index * 3);

                return [
                    'fieldName' => "lims_{$placement['semantic_role']}_g1",
                    'signed' => false,
                    'selfOnlyLock' => true,
                    'widgetCount' => 1,
                    'objectRef' => "{$objectNumber} 0 R",
                    'widgets' => [[
                        'widgetIndex' => 0,
                        'pageIndex' => $placement['page_index'],
                        'normalizedRectangle' => collect($placement['normalized_rect'])
                            ->map(fn (string $value): string => number_format((float) $value, 6, '.', ''))
                            ->all(),
                        'objectRef' => ($objectNumber + 1).' 0 R',
                        'appearanceObjectRefs' => [($objectNumber + 2).' 0 R'],
                    ]],
                ];
            },
        )->all();
    }

    /** @return list<array<string, mixed>> */
    private function placements(): array
    {
        return [
            ['semantic_role' => 'inspector', 'page_index' => 0, 'normalized_rect' => $this->rect('0.08', '0.70')],
            ['semantic_role' => 'reviewer', 'page_index' => 0, 'normalized_rect' => $this->rect('0.36', '0.70')],
            ['semantic_role' => 'issuer', 'page_index' => 1, 'normalized_rect' => $this->rect('0.08', '0.15')],
        ];
    }

    /** @return array{x: string, y: string, width: string, height: string} */
    private function rect(string $x, string $y, string $width = '0.24', string $height = '0.08'): array
    {
        return compact('x', 'y', 'width', 'height');
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

    /** @param  list<string>  $names */
    private function userWithPermissions(array $names): User
    {
        $user = User::factory()->create();

        $this->grantPermissions($user, $names);

        return $user;
    }

    /** @param  list<string>  $names */
    private function grantPermissions(User $user, array $names): void
    {
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user->givePermissionTo($names);
    }

    private function signaturePng(): string
    {
        $image = imagecreatetruecolor(320, 120);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        $ink = imagecolorallocatealpha($image, 10, 20, 30, 0);
        imagesetthickness($image, 8);
        imageline($image, 20, 90, 100, 35, $ink);
        imageline($image, 100, 35, 170, 88, $ink);
        imageline($image, 170, 88, 290, 28, $ink);
        ob_start();
        imagepng($image, null, 9, PNG_ALL_FILTERS);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return is_string($bytes) ? $bytes : '';
    }
}

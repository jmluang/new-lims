<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfFile;
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
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PdfSigningOperationService
{
    public function __construct(private readonly PdfImmutableFileStore $files) {}

    /**
     * @param  array<string, mixed>  $auditContext
     */
    public function claim(
        PdfSigningRequest $request,
        string $challengeUuid,
        string $idempotencyKey,
        string $authContextId,
        array $auditContext,
        User $actor,
    ): PdfSigningOperation {
        $scopeKey = "pdf-signing-request:{$request->request_uuid}:actor:{$actor->id}";
        $idempotencyFingerprint = hash('sha256', CanonicalJson::encode([
            'actor_user_id' => $actor->id,
            'challenge_uuid' => $challengeUuid,
            'request_uuid' => $request->request_uuid,
        ]));

        $existing = PdfSigningOperation::query()
            ->where('idempotency_scope_key', $scopeKey)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->returnIdempotentOrConflict($existing, $idempotencyFingerprint);
        }

        try {
            return DB::transaction(function () use (
                $request,
                $challengeUuid,
                $idempotencyKey,
                $scopeKey,
                $idempotencyFingerprint,
                $authContextId,
                $auditContext,
                $actor,
            ): PdfSigningOperation {
                $raced = PdfSigningOperation::query()
                    ->where('idempotency_scope_key', $scopeKey)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($raced !== null) {
                    return $this->returnIdempotentOrConflict($raced, $idempotencyFingerprint);
                }

                $workflowSnapshot = PdfSigningWorkflow::query()->findOrFail($request->workflow_id);
                $document = PdfDocument::query()->lockForUpdate()->findOrFail($workflowSnapshot->document_id);
                $workflow = PdfSigningWorkflow::query()->lockForUpdate()->findOrFail($workflowSnapshot->id);
                $lockedRequest = PdfSigningRequest::query()->lockForUpdate()->findOrFail($request->id);

                $challenge = PdfSigningChallenge::query()
                    ->where('challenge_uuid', $challengeUuid)
                    ->lockForUpdate()
                    ->firstOrFail();
                $appearance = PdfSignatureAppearanceArtifact::query()
                    ->lockForUpdate()
                    ->findOrFail($challenge->appearance_artifact_id);
                $revision = PdfFile::query()->lockForUpdate()->findOrFail($challenge->source_revision_id);
                $field = PdfSigningField::query()
                    ->where('request_id', $lockedRequest->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $field->setRelation('slots', PdfSigningSlot::query()
                    ->where('field_id', $field->id)
                    ->orderBy('widget_index')
                    ->lockForUpdate()
                    ->get());
                $act = PdfSigningAct::query()->lockForUpdate()->findOrFail($lockedRequest->signing_act_id);
                $policy = PdfSigningPolicyVersion::query()->findOrFail($challenge->signing_policy_version_id);

                $this->assertClaimable(
                    $document,
                    $workflow,
                    $lockedRequest,
                    $challenge,
                    $appearance,
                    $revision,
                    $policy,
                    $authContextId,
                    $actor,
                );
                $this->assertFieldClaimable($field, $act, $lockedRequest, $document);
                $this->files->verifiedAbsolutePath(
                    $revision->file_path,
                    $revision->sha256_hash,
                    (int) $revision->file_size,
                );
                $this->files->verifiedAbsolutePathByHash(
                    $appearance->file_path,
                    $appearance->canonical_image_sha256,
                );

                $fieldLockPolicyHash = hash('sha256', CanonicalJson::encode([
                    'field_name' => $field->field_name,
                    'lock_policy' => $field->lock_policy,
                    'prepared_object_ref' => $field->prepared_object_ref,
                    'slots' => $field->slots->map(fn ($slot): array => [
                        'geometry_hash' => $slot->geometry_hash,
                        'normalized_rect' => $slot->normalized_rect,
                        'page_index' => $slot->page_index,
                        'prepared_appearance_object_refs' => $slot->prepared_appearance_object_refs,
                        'prepared_widget_object_ref' => $slot->prepared_widget_object_ref,
                        'widget_index' => $slot->widget_index,
                    ])->values()->all(),
                ]));
                $command = [
                    'fieldName' => $field->field_name,
                    'location' => 'LIMS',
                    'reason' => "LIMS {$act->semantic_role} approval",
                    'signatureRole' => $act->pdf_signature_role,
                    'signerName' => $actor->name,
                ];
                $operationManifest = [
                    'action' => 'fill_signature_field',
                    'actor_user_id' => $actor->id,
                    'appearance_manifest_hash' => $appearance->appearance_manifest_hash,
                    'appearance_sha256' => $appearance->canonical_image_sha256,
                    'challenge_uuid' => $challenge->challenge_uuid,
                    'command' => $command,
                    'config_bundle_hash' => $policy->config_bundle_hash,
                    'document_uuid' => $document->document_uuid,
                    'expected_certificate_fingerprint' => $challenge->expected_certificate_fingerprint,
                    'field_lock_policy_hash' => $fieldLockPolicyHash,
                    'field_plan_hash' => $challenge->field_plan_hash,
                    'policy_hash' => $policy->policy_hash,
                    'signing_policy_version_id' => $policy->id,
                    'signing_policy_version_uuid' => $policy->version_uuid,
                    'request_uuid' => $lockedRequest->request_uuid,
                    'source_revision_uuid' => $revision->revision_uuid,
                    'source_sha256' => $revision->sha256_hash,
                    'version' => 'pdf-signing-operation-v1',
                    'workflow_uuid' => $workflow->workflow_uuid,
                ];
                $operationInputManifestHash = hash('sha256', CanonicalJson::encode($operationManifest));
                $inputFingerprint = hash('sha256', CanonicalJson::encode([
                    'operation_input_manifest_hash' => $operationInputManifestHash,
                    'policy_hash' => $policy->policy_hash,
                    'source_sha256' => $revision->sha256_hash,
                ]));
                $operationUuid = (string) Str::uuid();
                $resultRevisionUuid = (string) Str::uuid();
                $frozenAuditContext = [
                    ...$auditContext,
                    'command' => $command,
                    'idempotency_request_fingerprint' => $idempotencyFingerprint,
                    'operation_manifest' => $operationManifest,
                ];
                $operation = PdfSigningOperation::query()->create([
                    'operation_uuid' => $operationUuid,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_scope_key' => $scopeKey,
                    'scope_type' => 'request',
                    'actor_user_id' => $actor->id,
                    'document_id' => $document->id,
                    'workflow_id' => $workflow->id,
                    'request_id' => $lockedRequest->id,
                    'challenge_id' => $challenge->id,
                    'action' => 'fill_signature_field',
                    'input_fingerprint' => $inputFingerprint,
                    'operation_input_manifest_hash' => $operationInputManifestHash,
                    'expected_source_revision_id' => $revision->id,
                    'expected_source_sha256' => $revision->sha256_hash,
                    'signing_policy_version_id' => $policy->id,
                    'policy_hash' => $policy->policy_hash,
                    'config_bundle_hash' => $policy->config_bundle_hash,
                    'expected_certificate_fingerprint' => $challenge->expected_certificate_fingerprint,
                    'appearance_manifest_hash' => $appearance->appearance_manifest_hash,
                    'appearance_sha256' => $appearance->canonical_image_sha256,
                    'pdf_signature_role' => $command['signatureRole'],
                    'target_field_name' => $field->field_name,
                    'field_lock_policy_hash' => $fieldLockPolicyHash,
                    'result_revision_uuid' => $resultRevisionUuid,
                    'state' => 'claimed',
                    'stage' => 'awaiting_dispatch',
                    'audit_context' => $frozenAuditContext,
                    'audit_context_hash' => hash('sha256', CanonicalJson::encode($frozenAuditContext)),
                ]);
                $challenge->update(['consumed_at' => now()]);
                $appearance->update([
                    'state' => 'claimed',
                    'claimed_by_operation_id' => $operation->id,
                    'retention_until' => $this->laterOf($appearance->retention_until, now()->addDays(8)),
                    'lock_version' => $appearance->lock_version + 1,
                ]);
                $lockedRequest->update(['status' => 'signing']);
                $workflow->update(['status' => 'signing', 'active_operation_id' => $operation->id]);
                $document->update(['active_operation_id' => $operation->id]);

                $payloadHash = hash('sha256', CanonicalJson::encode([
                    'job_type' => 'execute_pdf_signing_operation',
                    'operation_uuid' => $operationUuid,
                ]));
                PdfOperationOutbox::query()->create([
                    'operation_id' => $operation->id,
                    'job_type' => 'execute_pdf_signing_operation',
                    'payload_hash' => $payloadHash,
                    'state' => 'pending',
                    'available_at' => now(),
                ]);

                return $operation;
            }, 3);
        } catch (ConflictHttpException $exception) {
            $raced = PdfSigningOperation::query()
                ->where('idempotency_scope_key', $scopeKey)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($raced !== null) {
                return $this->returnIdempotentOrConflict($raced, $idempotencyFingerprint);
            }

            throw $exception;
        }
    }

    private function returnIdempotentOrConflict(
        PdfSigningOperation $operation,
        string $idempotencyFingerprint,
    ): PdfSigningOperation {
        if (($operation->audit_context['idempotency_request_fingerprint'] ?? null) !== $idempotencyFingerprint) {
            throw new ConflictHttpException('PDF_IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_INPUT');
        }

        return $operation;
    }

    private function assertClaimable(
        PdfDocument $document,
        PdfSigningWorkflow $workflow,
        PdfSigningRequest $request,
        PdfSigningChallenge $challenge,
        PdfSignatureAppearanceArtifact $appearance,
        PdfFile $revision,
        PdfSigningPolicyVersion $policy,
        string $authContextId,
        User $actor,
    ): void {
        $passwordSnapshotMatches = $challenge->password_changed_at_snapshot === null
            ? $actor->password_changed_at === null
            : $actor->password_changed_at !== null
                && $challenge->password_changed_at_snapshot->equalTo($actor->password_changed_at);

        if ($request->assigned_user_id !== $actor->id || $request->status !== 'available'
            || $workflow->status !== 'ready'
            || $workflow->active_operation_id !== null || $document->active_operation_id !== null
            || $document->active_workflow_id !== $workflow->id || $document->integrity_state !== 'ok'
            || $document->evidence_hold_state !== 'none' || (int) $document->evidence_hold_mask !== 0
            || $challenge->request_id !== $request->id || $challenge->user_id !== $actor->id
            || $challenge->consumed_at !== null || $challenge->cancelled_at !== null
            || ! now()->lt($challenge->expires_at) || $challenge->auth_context_id !== $authContextId
            || ! $passwordSnapshotMatches
            || $appearance->request_id !== $request->id || $appearance->created_by_id !== $actor->id
            || $appearance->state !== 'available' || $appearance->claimed_by_operation_id !== null
            || $appearance->appearance_manifest_hash !== $challenge->appearance_manifest_hash
            || $appearance->evidence_hold_state !== 'none' || $appearance->retirement_state !== 'none'
            || $appearance->deleted_at !== null
            || $revision->id !== $request->expected_source_revision_id
            || $revision->id !== $challenge->source_revision_id
            || $revision->sha256_hash !== $request->expected_source_sha256
            || $revision->sha256_hash !== $challenge->source_sha256
            || $revision->integrity_state !== 'ready'
            || $policy->id !== $request->signing_policy_version_id
            || $policy->policy_hash !== $challenge->policy_hash
            || $policy->immutable_at === null) {
            throw new ConflictHttpException('PDF_SIGNING_OPERATION_SNAPSHOT_STALE');
        }
    }

    private function assertFieldClaimable(
        PdfSigningField $field,
        PdfSigningAct $act,
        PdfSigningRequest $request,
        PdfDocument $document,
    ): void {
        $expectedPdfRole = $request->sequence === 1 ? 'certification_p2' : 'approval';
        $bindingValid = $field->binding_mode === 'created_before_first_signature'
            ? $field->source_field_id === null
            : $field->binding_mode === 'rebound_existing' && $field->source_field_id !== null;
        if ($field->workflow_id !== $request->workflow_id
            || $field->signing_act_id !== $request->signing_act_id
            || $field->status !== 'prepared'
            || $field->activation_mode !== 'current'
            || ! $bindingValid
            || $field->lock_policy !== 'include_self_only'
            || ! is_string($field->prepared_object_ref)
            || preg_match('/^[1-9][0-9]* [0-9]+ R$/', $field->prepared_object_ref) !== 1
            || $field->slots->isEmpty()
            || $field->slots->contains(
                fn (PdfSigningSlot $slot): bool => $slot->status !== 'prepared'
                    || ! is_string($slot->prepared_widget_object_ref)
                    || preg_match('/^[1-9][0-9]* [0-9]+ R$/', $slot->prepared_widget_object_ref) !== 1
                    || ! is_array($slot->prepared_appearance_object_refs)
                    || $slot->prepared_appearance_object_refs === [],
            )
            || $act->document_id !== $document->id
            || $act->id !== $request->signing_act_id
            || (int) $act->sequence !== (int) $request->sequence
            || $act->status !== 'planned'
            || $act->pdf_signature_role !== $expectedPdfRole) {
            throw new ConflictHttpException('PDF_SIGNING_FIELD_SNAPSHOT_STALE');
        }
    }

    private function laterOf(?Carbon $left, Carbon $right): Carbon
    {
        return $left !== null && $left->greaterThan($right) ? $left : $right;
    }
}

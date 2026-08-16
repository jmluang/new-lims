<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfJavaSigningExecution;
use App\Models\PdfOperationOutbox;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ResolvePdfSigningManualReviewService
{
    private const MANUAL_REVIEW_HOLD = 1;

    private const IRREVERSIBLE_FAILURE_HOLD = 2;

    private const QUARANTINE_HOLD = 4;

    private const DOCUMENT_MANUAL_REVIEW_HOLD = 8;

    public function __construct(private readonly PdfRendererClient $renderer) {}

    public function resolve(
        PdfSigningOperation $operation,
        string $decision,
        string $reasonCode,
        string $resolutionFingerprint,
        User $actor,
    ): PdfSigningOperation {
        if (! in_array($decision, ['adopt_completed', 'confirmed_no_private_key', 'confirmed_no_usable_result'], true)
            || preg_match('/^[0-9a-f]{64}$/', $resolutionFingerprint) !== 1) {
            throw new \InvalidArgumentException('PDF_MANUAL_REVIEW_RESOLUTION_INVALID');
        }

        $adoptionEvidence = $decision === 'adopt_completed'
            ? $this->verifiedAdoptionEvidence($operation)
            : null;

        return DB::transaction(function () use (
            $operation, $decision, $reasonCode, $resolutionFingerprint, $actor, $adoptionEvidence,
        ): PdfSigningOperation {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $execution = PdfJavaSigningExecution::query()
                ->where('operation_uuid', $locked->operation_uuid)
                ->lockForUpdate()
                ->first();
            $document = PdfDocument::query()->lockForUpdate()->findOrFail($locked->document_id);
            $workflow = PdfSigningWorkflow::query()->lockForUpdate()->findOrFail($locked->workflow_id);
            $request = PdfSigningRequest::query()->lockForUpdate()->findOrFail($locked->request_id);
            $appearance = PdfSignatureAppearanceArtifact::query()
                ->where('claimed_by_operation_id', $locked->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->state !== 'manual_review') {
                throw new ConflictHttpException('PDF_OPERATION_NOT_IN_MANUAL_REVIEW');
            }

            match ($decision) {
                'adopt_completed' => $this->adoptCompleted(
                    $locked, $execution, $document, $workflow, $request, $appearance, $adoptionEvidence,
                ),
                'confirmed_no_private_key' => $this->confirmNoPrivateKey(
                    $locked, $execution, $document, $workflow, $request, $appearance,
                ),
                'confirmed_no_usable_result' => $this->confirmNoUsableResult(
                    $locked, $execution, $document, $workflow, $request, $appearance,
                ),
            };
            $this->appendEvent($locked, $decision, $reasonCode, $resolutionFingerprint, $actor);

            return $locked->refresh();
        }, 3);
    }

    private function adoptCompleted(
        PdfSigningOperation $operation,
        ?PdfJavaSigningExecution $execution,
        PdfDocument $document,
        PdfSigningWorkflow $workflow,
        PdfSigningRequest $request,
        PdfSignatureAppearanceArtifact $appearance,
        ?array $adoptionEvidence,
    ): void {
        if ($execution?->state !== 'completed' || $execution->result_integrity_state !== 'available'
            || $execution->result_sha256 === null || $execution->result_size === null
            || $adoptionEvidence === null
            || ! hash_equals($execution->result_sha256, $adoptionEvidence['sha256'])
            || (int) $execution->result_size !== $adoptionEvidence['size']) {
            throw new ConflictHttpException('PDF_MANUAL_REVIEW_COMPLETED_RESULT_NOT_ADOPTABLE');
        }
        $operation->update([
            'state' => 'processing',
            'stage' => 'java_polling',
            'java_execution_state' => 'completed',
            'java_request_started_at' => $operation->java_request_started_at ?? now(),
            'lease_owner' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => null,
            'error_code' => null,
            'error_retryability' => 'manual_adoption_result_only',
        ]);
        $request->update(['status' => 'signing']);
        $workflow->update(['status' => 'signing', 'active_operation_id' => $operation->id]);
        $document->update(['status' => 'signing', 'active_operation_id' => $operation->id]);
        $appearance->update([
            'state' => 'claimed',
            'lock_version' => $appearance->lock_version + 1,
        ]);
        $payloadHash = hash('sha256', CanonicalJson::encode([
            'job_type' => 'resume_pdf_operation_from_java_result',
            'operation_uuid' => $operation->operation_uuid,
        ]));
        PdfOperationOutbox::query()->updateOrCreate(
            ['operation_id' => $operation->id],
            [
                'job_type' => 'resume_pdf_operation_from_java_result',
                'payload_hash' => $payloadHash,
                'state' => 'pending',
                'available_at' => now(),
                'dispatched_at' => null,
                'last_error' => null,
            ],
        );
    }

    private function confirmNoPrivateKey(
        PdfSigningOperation $operation,
        ?PdfJavaSigningExecution $execution,
        PdfDocument $document,
        PdfSigningWorkflow $workflow,
        PdfSigningRequest $request,
        PdfSignatureAppearanceArtifact $appearance,
    ): void {
        if ($operation->promoted_file_path !== null
            || ($execution !== null && ($execution->private_key_started_at !== null
                || $execution->result_path !== null || $execution->result_sha256 !== null))) {
            throw new ConflictHttpException('PDF_MANUAL_REVIEW_PRIVATE_KEY_ABSENCE_NOT_PROVEN');
        }
        $operation->update([
            'state' => 'failed',
            'stage' => 'done',
            'error_code' => 'MANUAL_CONFIRMED_NO_PRIVATE_KEY',
            'error_retryability' => 'new_operation_same_workflow',
        ]);
        $request->update(['status' => 'available']);
        $workflow->update(['status' => 'ready', 'active_operation_id' => null]);
        $document->update([
            'status' => 'signing',
            'active_operation_id' => null,
            ...$this->documentHoldRelease($document),
        ]);
        $this->releaseAppearanceHold(
            $appearance,
            self::MANUAL_REVIEW_HOLD | self::IRREVERSIBLE_FAILURE_HOLD | self::QUARANTINE_HOLD,
        );
        $this->releaseExecutionHold(
            $operation,
            $execution,
            self::MANUAL_REVIEW_HOLD | self::IRREVERSIBLE_FAILURE_HOLD | self::QUARANTINE_HOLD,
        );
    }

    private function confirmNoUsableResult(
        PdfSigningOperation $operation,
        ?PdfJavaSigningExecution $execution,
        PdfDocument $document,
        PdfSigningWorkflow $workflow,
        PdfSigningRequest $request,
        PdfSignatureAppearanceArtifact $appearance,
    ): void {
        if ($execution === null || $execution->private_key_started_at === null
            || ! in_array($execution->state, ['outcome_unknown', 'failed_after_private_key_known'], true)
            || $operation->promoted_file_path !== null || $operation->result_revision_id !== null
            || $operation->result_sha256 !== null || $execution->result_path !== null
            || $execution->result_sha256 !== null || $request->completed_revision_id !== null) {
            throw new ConflictHttpException('PDF_MANUAL_REVIEW_IRREVERSIBLE_EVIDENCE_MISSING');
        }
        $operation->update([
            'state' => 'irreversible_failed',
            'stage' => 'done',
            'error_code' => 'MANUAL_CONFIRMED_NO_USABLE_RESULT',
            'error_retryability' => 'new_generation_only',
        ]);
        $request->update(['status' => 'failed']);
        $workflow->update(['status' => 'failed', 'active_operation_id' => null]);
        $document->update([
            'status' => $document->published_revision_id === null ? 'failed' : 'published',
            'active_operation_id' => null,
            'active_workflow_id' => null,
            ...$this->documentHoldRelease($document),
        ]);
        $this->releaseAppearanceHold(
            $appearance,
            self::MANUAL_REVIEW_HOLD | self::IRREVERSIBLE_FAILURE_HOLD | self::QUARANTINE_HOLD,
        );
        $this->releaseExecutionHold(
            $operation,
            $execution,
            self::MANUAL_REVIEW_HOLD | self::IRREVERSIBLE_FAILURE_HOLD | self::QUARANTINE_HOLD,
        );
    }

    private function releaseAppearanceHold(PdfSignatureAppearanceArtifact $appearance, int $ownedMask): void
    {
        $remainingMask = $appearance->evidence_hold_mask & ~$ownedMask;
        $appearance->update([
            'evidence_hold_mask' => $remainingMask,
            'evidence_hold_state' => $remainingMask === 0 ? 'none' : 'active',
            'hold_released_at' => $remainingMask === 0 ? now() : null,
            'retention_until' => $remainingMask === 0 ? now()->addDay() : $appearance->retention_until,
            'lock_version' => $appearance->lock_version + 1,
        ]);
    }

    /** @return array{sha256: string, size: int} */
    private function verifiedAdoptionEvidence(PdfSigningOperation $operation): array
    {
        $operation = PdfSigningOperation::query()->findOrFail($operation->id);
        $execution = PdfJavaSigningExecution::query()
            ->where('operation_uuid', $operation->operation_uuid)
            ->first();
        if ($operation->state !== 'manual_review' || $execution?->state !== 'completed'
            || $execution->result_integrity_state !== 'available') {
            throw new ConflictHttpException('PDF_MANUAL_REVIEW_COMPLETED_RESULT_NOT_ADOPTABLE');
        }
        $result = $this->renderer->signingExecutionResult(
            $operation->operation_uuid,
            $this->javaOperation($operation),
        );
        if (! hash_equals((string) $execution->result_sha256, $result['sha256'])
            || (int) $execution->result_size !== $result['size']) {
            throw new ConflictHttpException('PDF_MANUAL_REVIEW_COMPLETED_RESULT_NOT_ADOPTABLE');
        }
        $verification = $this->renderer->verifySignatureBytes($result['body']);
        $inspection = $this->renderer->inspectSignatureBytes($result['body']);
        $request = PdfSigningRequest::query()->findOrFail($operation->request_id);
        $target = collect($inspection['fields'] ?? [])->firstWhere('fieldName', $operation->target_field_name);
        if (($verification['documentCurrentState'] ?? null) !== 'valid'
            || (int) ($verification['docMdpPermission'] ?? 0) !== 2
            || count($verification['signatures'] ?? []) !== (int) $request->sequence
            || (int) ($inspection['signatureCount'] ?? -1) !== (int) $request->sequence
            || ! is_array($target) || ($target['signed'] ?? false) !== true) {
            throw new ConflictHttpException('PDF_MANUAL_REVIEW_COMPLETED_RESULT_NOT_ADOPTABLE');
        }

        return ['sha256' => $result['sha256'], 'size' => $result['size']];
    }

    /** @return array<string, mixed> */
    private function javaOperation(PdfSigningOperation $operation): array
    {
        return [
            'operationUuid' => $operation->operation_uuid,
            'javaGateVersion' => (int) $operation->java_gate_version,
            'leaseEpoch' => (int) $operation->lease_epoch,
            'operationInputManifestHash' => $operation->operation_input_manifest_hash,
            'inputFingerprint' => $operation->input_fingerprint,
            'expectedSourceSha256' => $operation->expected_source_sha256,
            'policyVersionId' => (int) $operation->signing_policy_version_id,
            'policyHash' => $operation->policy_hash,
            'configBundleHash' => $operation->config_bundle_hash,
            'appearanceManifestHash' => $operation->appearance_manifest_hash,
            'appearanceSha256' => $operation->appearance_sha256,
            'pdfSignatureRole' => $operation->pdf_signature_role,
            'targetFieldName' => $operation->target_field_name,
            'expectedCertificateFingerprint' => $operation->expected_certificate_fingerprint,
            'fieldLockPolicyHash' => $operation->field_lock_policy_hash,
        ];
    }

    /** @return array<string, mixed> */
    private function documentHoldRelease(PdfDocument $document): array
    {
        $remainingMask = $document->integrity_hold_mask & ~self::DOCUMENT_MANUAL_REVIEW_HOLD;
        $changed = $remainingMask !== $document->integrity_hold_mask;

        return [
            'integrity_hold_mask' => $remainingMask,
            'integrity_state' => $remainingMask === 0 ? 'ok' : 'hold',
            'integrity_hold_released_at' => $remainingMask === 0 ? now() : null,
            'integrity_version' => $document->integrity_version + ($changed ? 1 : 0),
        ];
    }

    private function releaseExecutionHold(
        PdfSigningOperation $operation,
        ?PdfJavaSigningExecution $execution,
        int $ownedMask,
    ): void {
        if ($execution === null) {
            return;
        }
        $remainingMask = $execution->evidence_hold_mask & ~$ownedMask;
        if ($remainingMask === $execution->evidence_hold_mask) {
            return;
        }
        $oldVersion = (int) $execution->lock_version;
        $execution->update([
            'evidence_hold_mask' => $remainingMask,
            'evidence_hold_state' => $remainingMask === 0 ? 'none' : 'active',
            'lock_version' => $oldVersion + 1,
        ]);
        $now = now();
        $event = [
            'operation_uuid' => $operation->operation_uuid,
            'attempt_number' => (int) $execution->attempt_number,
            'event_type' => 'EVIDENCE_HOLD_RELEASED',
            'old_state' => $execution->state,
            'new_state' => $execution->state,
            'old_lock_version' => $oldVersion,
            'new_lock_version' => $oldVersion + 1,
            'event_at' => $now->toISOString(),
        ];
        DB::table('pdf_java_signing_execution_events')->insert([
            ...$event,
            'event_at' => $now,
            'event_hash' => hash('sha256', CanonicalJson::encode($event)),
        ]);
    }

    private function appendEvent(
        PdfSigningOperation $operation,
        string $decision,
        string $reasonCode,
        string $resolutionFingerprint,
        User $actor,
    ): void {
        $previousHash = DB::table('pdf_signing_operation_events')
            ->where('operation_id', $operation->id)
            ->latest('id')
            ->value('event_hash');
        $payload = [
            'decision' => $decision,
            'operation_uuid' => $operation->operation_uuid,
            'reason_code' => $reasonCode,
            'resolution_fingerprint' => $resolutionFingerprint,
            'resulting_stage' => $operation->stage,
            'resulting_state' => $operation->state,
        ];
        $eventHash = hash('sha256', CanonicalJson::encode([
            'payload' => $payload,
            'previous_event_hash' => $previousHash,
        ]));
        DB::table('pdf_signing_operation_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'operation_id' => $operation->id,
            'event_type' => 'manual_review_resolved',
            'actor_user_id' => $actor->id,
            'reason_code' => $reasonCode,
            'resolution_fingerprint' => $resolutionFingerprint,
            'event_payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'previous_event_hash' => $previousHash,
            'event_hash' => $eventHash,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

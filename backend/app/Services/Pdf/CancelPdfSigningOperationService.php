<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfJavaSigningExecution;
use App\Models\PdfOperationOutbox;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningAct;
use App\Models\PdfSigningChallenge;
use App\Models\PdfSigningField;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningSlot;
use App\Models\PdfSigningWorkflow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CancelPdfSigningOperationService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function cancel(
        PdfSigningOperation $operation,
        string $reasonCode,
        User $actor,
    ): PdfSigningOperation {
        return $this->terminate($operation, $reasonCode, $actor, 'cancelled');
    }

    public function reject(
        PdfSigningOperation $operation,
        string $reasonCode,
        User $actor,
    ): PdfSigningOperation {
        return $this->terminate($operation, $reasonCode, $actor, 'rejected');
    }

    private function terminate(
        PdfSigningOperation $operation,
        string $reasonCode,
        User $actor,
        string $requestOutcome,
    ): PdfSigningOperation {
        return DB::transaction(function () use ($operation, $reasonCode, $actor, $requestOutcome): PdfSigningOperation {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->state === 'cancelled') {
                return $locked;
            }
            if (! in_array($locked->state, ['claimed', 'processing'], true)) {
                throw new ConflictHttpException('PDF_SIGNING_OPERATION_NO_LONGER_CANCELLABLE');
            }

            $execution = PdfJavaSigningExecution::query()
                ->where('operation_uuid', $locked->operation_uuid)
                ->lockForUpdate()
                ->first();
            if ($execution?->private_key_started_at !== null
                || ($execution !== null && ! in_array($execution->state, [
                    'claimed', 'executing', 'failed_before_private_key',
                ], true))) {
                throw new ConflictHttpException('SIGNING_IRREVERSIBLE_IN_PROGRESS');
            }
            $document = PdfDocument::query()->lockForUpdate()->findOrFail($locked->document_id);
            $workflow = PdfSigningWorkflow::query()->lockForUpdate()->findOrFail($locked->workflow_id);
            $requests = PdfSigningRequest::query()
                ->where('workflow_id', $workflow->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $request = $locked->request_id === null ? null : $requests->firstWhere('id', $locked->request_id);
            if ($locked->request_id !== null && ! $request instanceof PdfSigningRequest) {
                throw new ConflictHttpException('PDF_SIGNING_OPERATION_REQUEST_MISMATCH');
            }
            $requestIds = $requests->modelKeys();
            $fields = PdfSigningField::query()
                ->where('workflow_id', $workflow->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $fieldIds = $fields->modelKeys();
            $cancellableFieldIds = $fields
                ->reject(fn (PdfSigningField $field): bool => $field->status === 'signed')
                ->modelKeys();
            PdfSigningSlot::query()->whereIn('field_id', $fieldIds)->orderBy('id')->lockForUpdate()->get();
            $actIds = $fields->pluck('signing_act_id')->filter()->unique()->values();
            PdfSigningAct::query()->whereIn('id', $actIds)->orderBy('id')->lockForUpdate()->get();
            PdfSigningChallenge::query()->whereIn('request_id', $requestIds)->orderBy('id')->lockForUpdate()->get();
            $appearance = PdfSignatureAppearanceArtifact::query()
                ->where('claimed_by_operation_id', $locked->id)
                ->lockForUpdate()
                ->first();
            PdfSignatureAppearanceArtifact::query()
                ->whereIn('request_id', $requestIds)
                ->whereNull('claimed_by_operation_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $outbox = PdfOperationOutbox::query()
                ->where('operation_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($document->active_operation_id !== $locked->id
                || $workflow->active_operation_id !== $locked->id) {
                throw new ConflictHttpException('PDF_SIGNING_OPERATION_POINTER_CHANGED');
            }
            $now = now();
            $executionErrorCode = $requestOutcome === 'rejected'
                ? 'WORKFLOW_REJECTED_BEFORE_PRIVATE_KEY'
                : 'WORKFLOW_CANCELLED_BEFORE_PRIVATE_KEY';
            if ($execution !== null && in_array($execution->state, [
                'claimed', 'executing', 'failed_before_private_key',
            ], true)) {
                $beforeState = $execution->state;
                $beforeVersion = (int) $execution->lock_version;
                $execution->update([
                    'state' => 'failed_before_private_key',
                    'retryability' => 'none',
                    'next_retry_at' => null,
                    'error_code' => $executionErrorCode,
                    'terminal_at' => $now,
                    'lock_version' => $beforeVersion + 1,
                ]);
                $eventMaterial = [
                    'operation_uuid' => $locked->operation_uuid,
                    'attempt_number' => (int) $execution->attempt_number,
                    'event_type' => $requestOutcome === 'rejected' ? 'REJECTED_BEFORE_PRIVATE_KEY' : 'CANCELLED_BEFORE_PRIVATE_KEY',
                    'old_state' => $beforeState,
                    'new_state' => 'failed_before_private_key',
                    'old_lock_version' => $beforeVersion,
                    'new_lock_version' => $beforeVersion + 1,
                    'event_at' => $now->toISOString(),
                ];
                DB::table('pdf_java_signing_execution_events')->insert([
                    ...$eventMaterial,
                    'error_code' => $executionErrorCode,
                    'event_at' => $now,
                    'event_hash' => hash('sha256', CanonicalJson::encode($eventMaterial)),
                ]);
            }

            $locked->update([
                'state' => 'cancelled',
                'stage' => 'done',
                'cancellation_requested_at' => $locked->cancellation_requested_at ?? $now,
                'cancelled_at' => $now,
                'cancellation_reason_code' => $reasonCode,
                'cancelled_by_id' => $actor->id,
                'lease_epoch' => $locked->lease_epoch + 1,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
                'error_code' => $executionErrorCode,
                'error_retryability' => 'none',
            ]);
            $request?->update([
                'status' => $requestOutcome,
                'rejection_reason_code' => $requestOutcome === 'rejected' ? $reasonCode : null,
                'rejected_at' => $requestOutcome === 'rejected' ? $now : null,
                'rejected_by_id' => $requestOutcome === 'rejected' ? $actor->id : null,
            ]);
            PdfSigningRequest::query()
                ->where('workflow_id', $workflow->id)
                ->whereIn('status', ['pending', 'available'])
                ->update(['status' => 'cancelled']);
            PdfSigningChallenge::query()
                ->whereIn('request_id', $requestIds)
                ->whereNull('consumed_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => $now]);
            PdfSigningField::query()
                ->whereIn('id', $cancellableFieldIds)
                ->update(['status' => 'cancelled']);
            PdfSigningSlot::query()
                ->whereIn('field_id', $cancellableFieldIds)
                ->update(['status' => 'cancelled']);
            PdfSigningAct::query()
                ->whereIn('id', $actIds)
                ->whereIn('status', ['planned', 'deferred'])
                ->update(['status' => 'cancelled']);
            $workflowBefore = ['status' => $workflow->status, 'active_operation_id' => $workflow->active_operation_id];
            $workflow->update(['status' => $requestOutcome, 'active_operation_id' => null]);
            $document->update([
                'status' => $document->published_revision_id === null ? $requestOutcome : 'published',
                'active_operation_id' => null,
                'active_workflow_id' => null,
            ]);
            if ($appearance !== null) {
                $appearance->update([
                    'state' => 'quarantined',
                    'retention_until' => $appearance->retention_until !== null
                        && $appearance->retention_until->isAfter($now->copy()->addDay())
                            ? $appearance->retention_until
                            : $now->copy()->addDay(),
                    'lock_version' => $appearance->lock_version + 1,
                ]);
            }
            PdfSignatureAppearanceArtifact::query()
                ->whereIn('request_id', $requestIds)
                ->whereNull('claimed_by_operation_id')
                ->whereNotIn('state', ['retired', 'deleted'])
                ->where(function ($query) use ($now): void {
                    $query->whereNull('retention_until')->orWhere('retention_until', '<', $now->copy()->addDay());
                })
                ->update(['retention_until' => $now->copy()->addDay()]);
            if ($outbox !== null && in_array($outbox->state, ['pending', 'dispatched'], true)) {
                $outbox->update(['state' => 'cancelled']);
            }
            $this->auditLogger->record(
                actor: $actor,
                action: $requestOutcome === 'rejected' ? 'pdf.workflow.rejected' : 'pdf.workflow.cancelled',
                module: 'pdf.workflow',
                subject: $workflow,
                before: $workflowBefore,
                after: [
                    'status' => $requestOutcome,
                    'active_operation_id' => null,
                    'reason_code' => $reasonCode,
                    'operation_uuid' => $locked->operation_uuid,
                ],
            );

            return $locked->refresh();
        }, 3);
    }
}

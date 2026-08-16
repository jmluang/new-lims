<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
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

final class RejectPdfSigningRequestService
{
    public function __construct(
        private readonly CancelPdfSigningOperationService $operations,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function reject(PdfSigningRequest $request, string $reasonCode, User $actor): PdfSigningRequest
    {
        if ($request->assigned_user_id !== $actor->id) {
            throw new ConflictHttpException('PDF_SIGNING_REQUEST_NOT_REJECTABLE');
        }

        $activeOperationId = PdfSigningWorkflow::query()
            ->whereKey($request->workflow_id)
            ->value('active_operation_id');

        if ($activeOperationId !== null) {
            $operation = PdfSigningOperation::query()->findOrFail($activeOperationId);
            if ($operation->request_id !== $request->id) {
                throw new ConflictHttpException('PDF_WORKFLOW_HAS_DIFFERENT_ACTIVE_OPERATION');
            }
            $this->operations->reject($operation, $reasonCode, $actor);

            return $request->refresh();
        }

        return DB::transaction(function () use ($request, $reasonCode, $actor): PdfSigningRequest {
            $workflowSnapshot = PdfSigningWorkflow::query()->findOrFail($request->workflow_id);
            $document = PdfDocument::query()->lockForUpdate()->findOrFail($workflowSnapshot->document_id);
            $workflow = PdfSigningWorkflow::query()->lockForUpdate()->findOrFail($workflowSnapshot->id);
            $requests = PdfSigningRequest::query()
                ->where('workflow_id', $workflow->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedRequest = $requests->firstWhere('id', $request->id);
            if (! $lockedRequest instanceof PdfSigningRequest) {
                throw new ConflictHttpException('PDF_SIGNING_REQUEST_NOT_REJECTABLE');
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
            PdfSignatureAppearanceArtifact::query()
                ->whereIn('request_id', $requestIds)
                ->whereNull('claimed_by_operation_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedRequest->assigned_user_id !== $actor->id
                || $lockedRequest->status !== 'available'
                || $workflow->active_operation_id !== null
                || $document->active_operation_id !== null
                || $document->active_workflow_id !== $workflow->id) {
                throw new ConflictHttpException('PDF_SIGNING_REQUEST_NOT_REJECTABLE');
            }

            $lockedRequest->update([
                'status' => 'rejected',
                'rejection_reason_code' => $reasonCode,
                'rejected_at' => now(),
                'rejected_by_id' => $actor->id,
            ]);
            PdfSigningRequest::query()
                ->where('workflow_id', $workflow->id)
                ->whereIn('status', ['pending', 'available'])
                ->whereKeyNot($lockedRequest->id)
                ->update(['status' => 'cancelled']);
            $now = now();
            PdfSigningChallenge::query()
                ->whereIn('request_id', $requestIds)
                ->whereNull('consumed_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => $now]);
            PdfSignatureAppearanceArtifact::query()
                ->whereIn('request_id', $requestIds)
                ->whereNull('claimed_by_operation_id')
                ->whereNotIn('state', ['retired', 'deleted'])
                ->where(function ($query) use ($now): void {
                    $query->whereNull('retention_until')->orWhere('retention_until', '<', $now->copy()->addDay());
                })
                ->update(['retention_until' => $now->copy()->addDay()]);
            PdfSigningField::query()->whereIn('id', $cancellableFieldIds)->update(['status' => 'cancelled']);
            PdfSigningSlot::query()->whereIn('field_id', $cancellableFieldIds)->update(['status' => 'cancelled']);
            PdfSigningAct::query()
                ->whereIn('id', $actIds)
                ->whereIn('status', ['planned', 'deferred'])
                ->update(['status' => 'cancelled']);
            $workflowBefore = ['status' => $workflow->status, 'active_operation_id' => $workflow->active_operation_id];
            $workflow->update(['status' => 'rejected']);
            $document->update([
                'active_workflow_id' => null,
                'active_operation_id' => null,
                'status' => $document->published_revision_id === null ? 'rejected' : 'published',
            ]);
            $this->auditLogger->record(
                actor: $actor,
                action: 'pdf.workflow.rejected',
                module: 'pdf.workflow',
                subject: $workflow,
                before: $workflowBefore,
                after: [
                    'status' => 'rejected',
                    'active_operation_id' => null,
                    'reason_code' => $reasonCode,
                    'request_uuid' => $lockedRequest->request_uuid,
                ],
            );

            return $lockedRequest->refresh();
        }, 3);
    }
}

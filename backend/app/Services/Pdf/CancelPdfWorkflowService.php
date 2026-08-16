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

final class CancelPdfWorkflowService
{
    public function __construct(
        private readonly CancelPdfSigningOperationService $operations,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function cancel(PdfSigningWorkflow $workflow, string $reasonCode, User $actor): PdfSigningWorkflow
    {
        $activeOperationId = PdfSigningWorkflow::query()
            ->whereKey($workflow->id)
            ->value('active_operation_id');

        if ($activeOperationId !== null) {
            $operation = PdfSigningOperation::query()->findOrFail($activeOperationId);
            if ($operation->workflow_id !== $workflow->id) {
                throw new ConflictHttpException('PDF_WORKFLOW_ACTIVE_OPERATION_MISMATCH');
            }
            $this->operations->cancel($operation, $reasonCode, $actor);

            return $workflow->refresh()->load(['requests.act', 'fields.slots']);
        }

        return DB::transaction(function () use ($workflow, $reasonCode, $actor): PdfSigningWorkflow {
            $snapshot = PdfSigningWorkflow::query()->findOrFail($workflow->id);
            $document = PdfDocument::query()->lockForUpdate()->findOrFail($snapshot->document_id);
            $locked = PdfSigningWorkflow::query()->lockForUpdate()->findOrFail($snapshot->id);
            $requests = PdfSigningRequest::query()
                ->where('workflow_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $requestIds = $requests->modelKeys();
            $fields = PdfSigningField::query()
                ->where('workflow_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $fieldIds = $fields->modelKeys();
            $cancellableFieldIds = $fields
                ->reject(fn (PdfSigningField $field): bool => $field->status === 'signed')
                ->modelKeys();
            PdfSigningSlot::query()
                ->whereIn('field_id', $fieldIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $actIds = $fields->pluck('signing_act_id')->filter()->unique()->values();
            PdfSigningAct::query()
                ->whereIn('id', $actIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            PdfSigningChallenge::query()
                ->whereIn('request_id', $requestIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            PdfSignatureAppearanceArtifact::query()
                ->whereIn('request_id', $requestIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($locked->status === 'cancelled') {
                return $locked->load(['requests.act', 'fields.slots']);
            }
            if (! in_array($locked->status, ['draft', 'preparing', 'ready', 'signing'], true)
                || $locked->active_operation_id !== null
                || $document->active_operation_id !== null
                || $document->active_workflow_id !== $locked->id
                || $requests->contains(fn (PdfSigningRequest $request): bool => $request->status === 'signing')) {
                throw new ConflictHttpException('PDF_WORKFLOW_NOT_CANCELLABLE');
            }

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
            PdfSigningRequest::query()
                ->whereIn('id', $requestIds)
                ->whereIn('status', ['pending', 'available'])
                ->update(['status' => 'cancelled']);
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

            $before = ['status' => $locked->status, 'active_operation_id' => $locked->active_operation_id];
            $locked->update(['status' => 'cancelled', 'active_operation_id' => null]);
            $document->update([
                'status' => $document->published_revision_id === null ? 'cancelled' : 'published',
                'active_operation_id' => null,
                'active_workflow_id' => null,
            ]);
            $this->auditLogger->record(
                actor: $actor,
                action: 'pdf.workflow.cancelled',
                module: 'pdf.workflow',
                subject: $locked,
                before: $before,
                after: [
                    'status' => 'cancelled',
                    'active_operation_id' => null,
                    'reason_code' => $reasonCode,
                ],
            );

            return $locked->refresh()->load(['requests.act', 'fields.slots']);
        }, 3);
    }
}

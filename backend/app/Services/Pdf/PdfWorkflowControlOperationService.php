<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfOperationOutbox;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningWorkflow;
use App\Models\PdfSourceUpload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PdfWorkflowControlOperationService
{
    /** @param array<string, mixed> $auditContext */
    public function claimFinalize(
        PdfSourceUpload $source,
        string $idempotencyKey,
        array $auditContext,
        User $actor,
    ): PdfSigningOperation {
        $scopeKey = "pdf-source-finalize:{$source->source_uuid}:actor:{$actor->id}";
        $manifest = [
            'action' => 'unsigned_finalize',
            'actor_user_id' => $actor->id,
            'source_uuid' => $source->source_uuid,
            'source_sha256' => $source->sha256,
            'source_size' => (int) $source->file_size,
            'inspection_manifest_hash' => $source->inspection_manifest_hash,
            'version' => 'pdf-control-operation-v1',
        ];

        return $this->claim(
            action: 'unsigned_finalize',
            scopeKey: $scopeKey,
            idempotencyKey: $idempotencyKey,
            manifest: $manifest,
            auditContext: $auditContext,
            actor: $actor,
            documentId: (int) $source->document_id,
            workflowId: null,
            expectedSourceRevision: null,
            precondition: function (PdfDocument $document) use ($source, $actor): void {
                $lockedSource = PdfSourceUpload::query()->lockForUpdate()->findOrFail($source->id);
                if ((int) $lockedSource->created_by_id !== (int) $actor->id
                    || (int) $document->created_by_id !== (int) $actor->id
                    || $lockedSource->document_id !== $document->id
                    || $lockedSource->status !== 'bound'
                    || $lockedSource->expires_at->isPast()
                    || $document->status !== 'draft'
                    || $document->active_workflow_id !== null
                    || $document->active_operation_id !== null
                    || $document->integrity_state !== 'ok'
                    || $document->evidence_hold_state !== 'none'
                    || (int) $document->evidence_hold_mask !== 0) {
                    throw new ConflictHttpException('PDF_SOURCE_NOT_FINALIZABLE');
                }
            },
        );
    }

    /** @param array<string, mixed> $auditContext */
    public function claimPrepare(
        PdfSigningWorkflow $workflow,
        string $idempotencyKey,
        array $auditContext,
        User $actor,
    ): PdfSigningOperation {
        $planning = PdfFile::query()->findOrFail($workflow->planning_revision_id);
        $scopeKey = "pdf-workflow-prepare:{$workflow->workflow_uuid}:actor:{$actor->id}";
        $manifest = [
            'action' => 'prepare_fields',
            'actor_user_id' => $actor->id,
            'workflow_uuid' => $workflow->workflow_uuid,
            'planning_revision_uuid' => $planning->revision_uuid,
            'planning_revision_sha256' => $planning->sha256_hash,
            'field_plan_hash' => $workflow->field_plan_hash,
            'placement_plan_hash' => $workflow->placement_plan_hash,
            'version' => 'pdf-control-operation-v1',
        ];

        return $this->claim(
            action: 'prepare_fields',
            scopeKey: $scopeKey,
            idempotencyKey: $idempotencyKey,
            manifest: $manifest,
            auditContext: $auditContext,
            actor: $actor,
            documentId: (int) $workflow->document_id,
            workflowId: (int) $workflow->id,
            expectedSourceRevision: $planning,
            precondition: function (PdfDocument $document) use ($workflow, $planning): void {
                $locked = PdfSigningWorkflow::query()->lockForUpdate()->findOrFail($workflow->id);
                $lockedPlanning = PdfFile::query()->lockForUpdate()->findOrFail($planning->id);
                if ($locked->status !== 'draft'
                    || $locked->planning_revision_id !== $lockedPlanning->id
                    || $lockedPlanning->revision_role !== 'finalized_unsigned'
                    || $lockedPlanning->integrity_state !== 'ready'
                    || $document->active_workflow_id !== $locked->id
                    || $document->active_operation_id !== null
                    || $locked->active_operation_id !== null
                    || $document->integrity_state !== 'ok'
                    || $document->evidence_hold_state !== 'none'
                    || (int) $document->evidence_hold_mask !== 0) {
                    throw new ConflictHttpException('PDF_WORKFLOW_NOT_PREPARABLE');
                }
            },
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $auditContext
     * @param  \Closure(PdfDocument): void  $precondition
     */
    private function claim(
        string $action,
        string $scopeKey,
        string $idempotencyKey,
        array $manifest,
        array $auditContext,
        User $actor,
        int $documentId,
        ?int $workflowId,
        ?PdfFile $expectedSourceRevision,
        \Closure $precondition,
    ): PdfSigningOperation {
        $idempotencyFingerprint = hash('sha256', CanonicalJson::encode([
            'action' => $action,
            'actor_user_id' => $actor->id,
            'manifest' => $manifest,
        ]));
        $existing = PdfSigningOperation::query()
            ->where('idempotency_scope_key', $scopeKey)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $this->returnIdempotentOrConflict($existing, $idempotencyFingerprint);
        }

        return DB::transaction(function () use (
            $action,
            $scopeKey,
            $idempotencyKey,
            $idempotencyFingerprint,
            $manifest,
            $auditContext,
            $actor,
            $documentId,
            $workflowId,
            $expectedSourceRevision,
            $precondition,
        ): PdfSigningOperation {
            $raced = PdfSigningOperation::query()
                ->where('idempotency_scope_key', $scopeKey)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($raced !== null) {
                return $this->returnIdempotentOrConflict($raced, $idempotencyFingerprint);
            }

            $document = PdfDocument::query()->lockForUpdate()->findOrFail($documentId);
            $precondition($document);
            $operationManifestHash = hash('sha256', CanonicalJson::encode($manifest));
            $inputFingerprint = hash('sha256', CanonicalJson::encode([
                'operation_input_manifest_hash' => $operationManifestHash,
                'source_sha256' => $expectedSourceRevision?->sha256_hash
                    ?? $manifest['source_sha256']
                    ?? $manifest['planning_revision_sha256'],
            ]));
            $operation = PdfSigningOperation::query()->create([
                'operation_uuid' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'idempotency_scope_key' => $scopeKey,
                'scope_type' => $workflowId === null ? 'document' : 'workflow',
                'actor_user_id' => $actor->id,
                'document_id' => $document->id,
                'workflow_id' => $workflowId,
                'action' => $action,
                'input_fingerprint' => $inputFingerprint,
                'operation_input_manifest_hash' => $operationManifestHash,
                'expected_source_revision_id' => $expectedSourceRevision?->id,
                'expected_source_sha256' => $expectedSourceRevision?->sha256_hash
                    ?? $manifest['source_sha256']
                    ?? $manifest['planning_revision_sha256'],
                'result_revision_uuid' => (string) Str::uuid(),
                'state' => 'claimed',
                'stage' => 'awaiting_dispatch',
                'audit_context' => [
                    ...$auditContext,
                    'idempotency_request_fingerprint' => $idempotencyFingerprint,
                    'operation_manifest' => $manifest,
                ],
                'audit_context_hash' => hash('sha256', CanonicalJson::encode([
                    ...$auditContext,
                    'idempotency_request_fingerprint' => $idempotencyFingerprint,
                    'operation_manifest' => $manifest,
                ])),
            ]);
            $document->update(['active_operation_id' => $operation->id]);
            if ($workflowId !== null) {
                PdfSigningWorkflow::query()->whereKey($workflowId)->update([
                    'status' => 'preparing',
                    'active_operation_id' => $operation->id,
                ]);
            }
            $jobType = 'execute_pdf_workflow_control_operation';
            PdfOperationOutbox::query()->create([
                'operation_id' => $operation->id,
                'job_type' => $jobType,
                'payload_hash' => hash('sha256', CanonicalJson::encode([
                    'job_type' => $jobType,
                    'operation_uuid' => $operation->operation_uuid,
                ])),
                'state' => 'pending',
                'available_at' => now(),
            ]);

            return $operation;
        }, 3);
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
}

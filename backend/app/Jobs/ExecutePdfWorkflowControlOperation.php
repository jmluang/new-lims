<?php

namespace App\Jobs;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfSigningField;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningSlot;
use App\Models\PdfSigningWorkflow;
use App\Models\PdfSourceUpload;
use App\Models\User;
use App\Services\Pdf\PdfImmutableFileStore;
use App\Services\Pdf\PdfRendererClient;
use App\Services\Pdf\PdfRevisionService;
use App\Services\Pdf\PdfSigningNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ExecutePdfWorkflowControlOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $operationUuid) {}

    public function handle(
        PdfRendererClient $renderer,
        PdfImmutableFileStore $files,
        PdfRevisionService $revisions,
    ): void {
        $activation = $this->activate();
        $operation = $activation['operation'];
        if (in_array($operation->state, ['completed', 'cancelled', 'failed', 'manual_review'], true)) {
            return;
        }
        if ($operation->state === 'promoted') {
            $this->commitPromoted($operation, $renderer, $files, $revisions);

            return;
        }
        if (! $activation['should_execute']) {
            return;
        }

        $maximumSize = (int) config('pdf_service.workflow.generated_revision_max_bytes', 33_554_432);
        if ($operation->result_sha256 !== null && $operation->result_size !== null) {
            try {
                $fallback = $files->readOperationCandidateFallback(
                    $operation->operation_uuid,
                    $operation->result_revision_uuid,
                    $operation->result_sha256,
                    (int) $operation->result_size,
                    $maximumSize,
                );
                $bytes = $fallback['body'];
                $candidate = $files->adoptOperationCandidate(
                    $fallback['path'],
                    $this->stagingPath($operation),
                    $this->finalPath($operation),
                    $operation->result_sha256,
                    (int) $operation->result_size,
                );
            } catch (RuntimeException) {
                $bytes = $this->generateBytes($operation, $renderer, $files);
                if (! hash_equals($operation->result_sha256, hash('sha256', $bytes))
                    || (int) $operation->result_size !== strlen($bytes)) {
                    throw new RuntimeException('PDF control operation cannot reproduce its frozen output identity.');
                }
                $candidate = $files->ensureOperationCandidate(
                    $bytes,
                    $this->stagingPath($operation),
                    $this->finalPath($operation),
                    $operation->result_sha256,
                    (int) $operation->result_size,
                );
            }
        } else {
            $bytes = $this->generateBytes($operation, $renderer, $files);
            if (strlen($bytes) > $maximumSize) {
                throw new RuntimeException('PDF control operation output exceeds its generated revision budget.');
            }
            $operation = $this->freezeResultIdentity($operation, $bytes);
            $candidate = $files->ensureOperationCandidate(
                $bytes,
                $this->stagingPath($operation),
                $this->finalPath($operation),
                $operation->result_sha256,
                (int) $operation->result_size,
            );
        }

        $this->validateBytes($operation, $bytes, $renderer);
        $operation = $this->advance($operation, 'verifying');
        if ($candidate['location'] === 'staging') {
            $operation = $this->advance($operation, 'promoting');
            $candidate = $files->promoteOperationCandidate(
                $this->stagingPath($operation),
                $this->finalPath($operation),
                $operation->result_sha256,
                (int) $operation->result_size,
            );
        } else {
            $operation = $this->advance($operation, 'promoting');
        }
        $operation = $this->recordPromoted($operation, $candidate);
        $this->commitPromoted($operation, $renderer, $files, $revisions);
    }

    /** @return array{operation: PdfSigningOperation, should_execute: bool} */
    private function activate(): array
    {
        return DB::transaction(function (): array {
            $operation = PdfSigningOperation::query()
                ->where('operation_uuid', $this->operationUuid)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($operation->action, ['unsigned_finalize', 'prepare_fields'], true)) {
                throw new RuntimeException('PDF control worker received an unsupported operation action.');
            }
            if ($operation->state !== 'claimed' || $operation->stage !== 'awaiting_dispatch') {
                return ['operation' => $operation, 'should_execute' => false];
            }
            $now = now();
            $operation->update([
                'state' => 'processing',
                'stage' => 'staging',
                'lease_owner' => (string) Str::uuid(),
                'lease_epoch' => $operation->lease_epoch + 1,
                'lease_expires_at' => $now->copy()->addSeconds(
                    max(30, (int) config('pdf_service.workflow.operation_lease_seconds', 300)),
                ),
                'heartbeat_at' => $now,
            ]);

            return ['operation' => $operation->refresh(), 'should_execute' => true];
        }, 3);
    }

    private function generateBytes(
        PdfSigningOperation $operation,
        PdfRendererClient $renderer,
        PdfImmutableFileStore $files,
    ): string {
        if ($operation->action === 'unsigned_finalize') {
            $sourceUuid = (string) ($operation->audit_context['operation_manifest']['source_uuid'] ?? '');
            $source = PdfSourceUpload::query()->where('source_uuid', $sourceUuid)->firstOrFail();
            if ((int) $source->file_size > (int) config('pdf_service.workflow.source_max_bytes', 20_971_520)) {
                throw new RuntimeException('PDF source exceeds the workflow input budget.');
            }
            $path = $files->verifiedAbsolutePath($source->stored_path, $source->sha256, (int) $source->file_size);

            return $renderer->finalizeUnsignedPdf($path);
        }

        $workflow = PdfSigningWorkflow::query()->findOrFail($operation->workflow_id);
        $workflow->load(['fields.slots']);
        $planning = PdfFile::query()->findOrFail($operation->expected_source_revision_id);
        if ((int) $planning->file_size > (int) config('pdf_service.workflow.source_max_bytes', 20_971_520)) {
            throw new RuntimeException('PDF planning revision exceeds the workflow input budget.');
        }
        $path = $files->verifiedAbsolutePath(
            $planning->file_path,
            $planning->sha256_hash,
            (int) $planning->file_size,
        );

        return $renderer->prepareSignatureFields($path, $this->fieldPlan($workflow));
    }

    private function freezeResultIdentity(PdfSigningOperation $operation, string $bytes): PdfSigningOperation
    {
        return DB::transaction(function () use ($operation, $bytes): PdfSigningOperation {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $this->assertFence($locked, $operation);
            $sha256 = hash('sha256', $bytes);
            $size = strlen($bytes);
            if (($locked->result_sha256 !== null && ! hash_equals($locked->result_sha256, $sha256))
                || ($locked->result_size !== null && (int) $locked->result_size !== $size)) {
                throw new RuntimeException('PDF control operation output identity changed across one operation.');
            }
            $locked->update([
                'result_sha256' => $sha256,
                'result_size' => $size,
                'heartbeat_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    private function advance(PdfSigningOperation $operation, string $targetStage): PdfSigningOperation
    {
        $order = ['staging' => 0, 'verifying' => 1, 'promoting' => 2];

        return DB::transaction(function () use ($operation, $targetStage, $order): PdfSigningOperation {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $this->assertFence($locked, $operation);
            if ($locked->state !== 'processing' || ! isset($order[$locked->stage], $order[$targetStage])) {
                throw new RuntimeException('PDF control operation stage is no longer eligible.');
            }
            if ($order[$locked->stage] < $order[$targetStage]) {
                $locked->update(['stage' => $targetStage, 'heartbeat_at' => now()]);
            }

            return $locked->refresh();
        }, 3);
    }

    /** @param array{location: 'final', path: string, absolute_path: string, sha256: string, size: int} $candidate */
    private function recordPromoted(PdfSigningOperation $operation, array $candidate): PdfSigningOperation
    {
        return DB::transaction(function () use ($operation, $candidate): PdfSigningOperation {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $this->assertFence($locked, $operation);
            if ($locked->state !== 'processing' || $locked->stage !== 'promoting'
                || ! hash_equals((string) $locked->result_sha256, $candidate['sha256'])
                || (int) $locked->result_size !== $candidate['size']) {
                throw new RuntimeException('PDF control operation lost its promotion fence.');
            }
            $locked->update([
                'state' => 'promoted',
                'stage' => 'committing',
                'promoted_file_path' => $candidate['path'],
                'heartbeat_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    private function commitPromoted(
        PdfSigningOperation $operation,
        PdfRendererClient $renderer,
        PdfImmutableFileStore $files,
        PdfRevisionService $revisions,
    ): void {
        $snapshot = PdfSigningOperation::query()->findOrFail($operation->id);
        if ($snapshot->state === 'completed') {
            return;
        }
        if ($snapshot->state !== 'promoted' || $snapshot->stage !== 'committing'
            || $snapshot->promoted_file_path === null
            || $snapshot->result_sha256 === null || $snapshot->result_size === null) {
            throw new RuntimeException('PDF control operation is not ready for transaction B.');
        }
        $bytes = $files->readVerifiedImmutableFile(
            $snapshot->promoted_file_path,
            $snapshot->result_sha256,
            (int) $snapshot->result_size,
            (int) config('pdf_service.workflow.generated_revision_max_bytes', 33_554_432),
        );
        $inspection = $this->validateBytes($snapshot, $bytes, $renderer);

        DB::transaction(function () use ($operation, $inspection, $revisions): void {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->state === 'completed') {
                return;
            }
            if ($locked->state !== 'promoted' || $locked->stage !== 'committing'
                || $locked->promoted_file_path === null
                || $locked->result_sha256 === null || $locked->result_size === null) {
                throw new RuntimeException('PDF control operation is not ready for transaction B.');
            }
            $document = PdfDocument::query()->lockForUpdate()->findOrFail($locked->document_id);
            if ($document->active_operation_id !== $locked->id) {
                throw new RuntimeException('PDF control operation document pointer changed before transaction B.');
            }
            $actor = User::query()->findOrFail($locked->actor_user_id);

            if ($locked->action === 'unsigned_finalize') {
                $sourceUuid = (string) ($locked->audit_context['operation_manifest']['source_uuid'] ?? '');
                $source = PdfSourceUpload::query()->where('source_uuid', $sourceUuid)->lockForUpdate()->firstOrFail();
                if ($source->document_id !== $document->id || ! in_array($source->status, ['bound', 'consumed'], true)) {
                    throw new RuntimeException('PDF source finalization snapshot changed before transaction B.');
                }
                $revision = $revisions->registerOperationRevision(
                    $document,
                    null,
                    $locked->result_revision_uuid,
                    'finalized_unsigned',
                    $locked->promoted_file_path,
                    $locked->result_sha256,
                    (int) $locked->result_size,
                    $actor,
                    [
                        'version' => 'unsigned-finalization-operation-v1',
                        'operation_uuid' => $locked->operation_uuid,
                        'source_uuid' => $source->source_uuid,
                        'source_sha256' => $source->sha256,
                        'final_inspection' => $inspection,
                    ],
                );
                $source->update(['status' => 'consumed', 'consumed_at' => $source->consumed_at ?? now()]);
            } else {
                $workflow = PdfSigningWorkflow::query()->lockForUpdate()->findOrFail($locked->workflow_id);
                $planning = PdfFile::query()->lockForUpdate()->findOrFail($locked->expected_source_revision_id);
                if ($workflow->status !== 'preparing'
                    || $workflow->active_operation_id !== $locked->id
                    || $workflow->planning_revision_id !== $planning->id) {
                    throw new RuntimeException('PDF workflow preparation snapshot changed before transaction B.');
                }
                $revision = $revisions->registerOperationRevision(
                    $document,
                    $planning,
                    $locked->result_revision_uuid,
                    'prepared',
                    $locked->promoted_file_path,
                    $locked->result_sha256,
                    (int) $locked->result_size,
                    $actor,
                    [
                        'version' => 'field-preparation-operation-v1',
                        'operation_uuid' => $locked->operation_uuid,
                        'workflow_uuid' => $workflow->workflow_uuid,
                        'field_plan_hash' => $workflow->field_plan_hash,
                        'inspection' => $inspection,
                    ],
                );
                $inspectedFields = collect($inspection['fields'])->keyBy('fieldName');
                $preparedFields = PdfSigningField::query()
                    ->where('workflow_id', $workflow->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($preparedFields as $preparedField) {
                    $inspectedField = $inspectedFields->get($preparedField->field_name);
                    if (! is_array($inspectedField)) {
                        throw new RuntimeException('Prepared field inspection disappeared before transaction B.');
                    }
                    $preparedField->update([
                        'prepared_revision_id' => $revision->id,
                        'prepared_object_ref' => $inspectedField['objectRef'],
                        'status' => 'prepared',
                    ]);
                    $inspectedWidgets = collect($inspectedField['widgets'])->keyBy('widgetIndex');
                    $slots = PdfSigningSlot::query()
                        ->where('field_id', $preparedField->id)
                        ->orderBy('widget_index')
                        ->lockForUpdate()
                        ->get();
                    foreach ($slots as $slot) {
                        $inspectedWidget = $inspectedWidgets->get($slot->widget_index);
                        if (! is_array($inspectedWidget)) {
                            throw new RuntimeException('Prepared widget inspection disappeared before transaction B.');
                        }
                        $slot->update([
                            'prepared_widget_object_ref' => $inspectedWidget['objectRef'],
                            'prepared_appearance_object_refs' => $inspectedWidget['appearanceObjectRefs'],
                            'status' => 'prepared',
                        ]);
                    }
                }
                $firstRequest = PdfSigningRequest::query()
                    ->where('workflow_id', $workflow->id)
                    ->where('sequence', 1)
                    ->lockForUpdate()
                    ->firstOrFail();
                $firstRequest->update([
                    'status' => 'available',
                    'expected_source_revision_id' => $revision->id,
                    'expected_source_sha256' => $revision->sha256_hash,
                ]);
                // Only the first signer can act yet; the rest hear from us when
                // the signature in front of them lands.
                app(PdfSigningNotifier::class)->notifyAvailable($firstRequest);
                $workflow->update([
                    'prepared_revision_id' => $revision->id,
                    'current_revision_id' => $revision->id,
                    'status' => 'ready',
                    'active_operation_id' => null,
                ]);
            }

            $locked->update([
                'result_revision_id' => $revision->id,
                'state' => 'completed',
                'stage' => 'done',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => now(),
            ]);
            $document->update(['active_operation_id' => null]);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function validateBytes(
        PdfSigningOperation $operation,
        string $bytes,
        PdfRendererClient $renderer,
    ): array {
        $inspection = $renderer->inspectSignatureBytes($bytes);
        if (($inspection['encrypted'] ?? true) !== false
            || ($inspection['sha256'] ?? null) !== hash('sha256', $bytes)
            || (int) ($inspection['signatureCount'] ?? -1) !== 0
            || ($inspection['docMdpPermission'] ?? null) !== null
            || (int) ($inspection['pageCount'] ?? 0) < 1) {
            throw new RuntimeException('PDF control operation output is not an eligible unsigned revision.');
        }
        if ($operation->action === 'prepare_fields') {
            $workflow = PdfSigningWorkflow::query()->findOrFail($operation->workflow_id);
            $actual = collect($inspection['fields'] ?? [])->keyBy('fieldName');
            $fieldPlan = $this->fieldPlan($workflow->loadMissing(['fields.slots']));
            $plannedFields = collect($fieldPlan)->groupBy('fieldName');
            if ($actual->count() !== $plannedFields->count()) {
                throw new RuntimeException('Prepared revision contains an unexpected signature field set.');
            }
            foreach ($plannedFields as $fieldName => $plannedWidgets) {
                $field = $actual->get($fieldName);
                if (! is_array($field) || ($field['signed'] ?? true) !== false
                    || ($field['selfOnlyLock'] ?? false) !== true
                    || (int) ($field['widgetCount'] ?? -1) !== $plannedWidgets->count()
                    || ! is_string($field['objectRef'] ?? null)
                    || preg_match('/^[1-9][0-9]* [0-9]+ R$/', $field['objectRef']) !== 1
                    || ! is_array($field['widgets'] ?? null)
                    || count($field['widgets']) !== $plannedWidgets->count()) {
                    throw new RuntimeException('Prepared revision did not preserve its exact field plan.');
                }
                $widgets = collect($field['widgets'])->keyBy('widgetIndex');
                foreach ($plannedWidgets->values() as $widgetIndex => $planned) {
                    $widget = $widgets->get($widgetIndex);
                    if (! is_array($widget)
                        || (int) ($widget['pageIndex'] ?? -1) !== (int) $planned['pageIndex']
                        || ($widget['normalizedRectangle'] ?? null) !== $planned['rectangle']
                        || ! is_string($widget['objectRef'] ?? null)
                        || preg_match('/^[1-9][0-9]* [0-9]+ R$/', $widget['objectRef']) !== 1
                        || ! is_array($widget['appearanceObjectRefs'] ?? null)
                        || $widget['appearanceObjectRefs'] === []
                        || collect($widget['appearanceObjectRefs'])->contains(
                            fn (mixed $reference): bool => ! is_string($reference)
                                || preg_match('/^[1-9][0-9]* [0-9]+ R$/', $reference) !== 1,
                        )) {
                        throw new RuntimeException('Prepared revision did not preserve its exact widget plan.');
                    }
                }
            }
        }

        return $inspection;
    }

    /** @return list<array<string, mixed>> */
    private function fieldPlan(PdfSigningWorkflow $workflow): array
    {
        return $workflow->fields->flatMap(
            fn (PdfSigningField $field) => $field->slots
                ->sortBy('widget_index')
                ->map(fn ($slot): array => [
                    'fieldName' => $field->field_name,
                    'pageIndex' => $slot->page_index,
                    'rectangle' => $slot->normalized_rect,
                    'deferred' => $field->activation_mode === 'deferred',
                ]),
        )->values()->all();
    }

    private function assertFence(PdfSigningOperation $locked, PdfSigningOperation $expected): void
    {
        if ($locked->state !== 'processing'
            || $locked->lease_owner === null
            || $locked->lease_owner !== $expected->lease_owner
            || (int) $locked->lease_epoch !== (int) $expected->lease_epoch
            || $locked->lease_expires_at === null
            || $locked->lease_expires_at->lte(now())) {
            throw new RuntimeException('PDF control operation lost its worker fence.');
        }
    }

    private function stagingPath(PdfSigningOperation $operation): string
    {
        return "workflow/staging/{$operation->operation_uuid}/{$operation->lease_epoch}/candidate.pdf";
    }

    private function finalPath(PdfSigningOperation $operation): string
    {
        return "workflow/revisions/{$operation->result_revision_uuid}/{$operation->operation_uuid}/{$operation->lease_epoch}/document.pdf";
    }
}

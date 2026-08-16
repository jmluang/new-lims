<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfJavaSigningExecution;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningOperation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PdfRevisionIntegrityService
{
    private const PUBLISHED_REVISION_INTEGRITY = 1;

    private const RETIREMENT_INTEGRITY = 8;

    public function __construct(
        private readonly PdfImmutableFileStore $files,
        private readonly PdfRendererClient $renderer,
    ) {}

    public function sweep(int $limit = 100): int
    {
        $revisions = PdfFile::query()
            ->where('integrity_state', 'ready')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('pdf_document_publication_events')
                    ->whereColumn('pdf_document_publication_events.revision_id', 'pdf_files.id')
                    ->where('pdf_document_publication_events.event_type', 'published');
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->get();
        $withdrawn = 0;
        foreach ($revisions as $revision) {
            try {
                $this->files->verifiedAbsolutePath(
                    $revision->file_path,
                    $revision->sha256_hash,
                    (int) $revision->file_size,
                );
            } catch (\Throwable $exception) {
                $withdrawn += $this->withdraw($revision, 'PUBLISHED_REVISION_STORAGE_INTEGRITY_FAILURE') ? 1 : 0;
            }
        }

        return $withdrawn;
    }

    public function withdraw(PdfFile $revision, string $reasonCode): bool
    {
        if (preg_match('/^[A-Z0-9][A-Z0-9_:-]{2,95}$/', $reasonCode) !== 1
            || $revision->document_id === null) {
            throw new RuntimeException('PDF_REVISION_INTEGRITY_WITHDRAWAL_INPUT_INVALID');
        }

        return DB::transaction(function () use ($revision, $reasonCode): bool {
            $scope = $this->lockScope((int) $revision->document_id);
            /** @var PdfDocument $document */
            $document = $scope['document'];
            /** @var PdfFile $lockedRevision */
            $lockedRevision = $scope['revisions']->firstWhere('id', $revision->id);
            if (! $lockedRevision instanceof PdfFile) {
                throw new RuntimeException('PDF_REVISION_INTEGRITY_TARGET_CHANGED');
            }
            try {
                $this->files->verifiedAbsolutePath(
                    $lockedRevision->file_path,
                    $lockedRevision->sha256_hash,
                    (int) $lockedRevision->file_size,
                );

                return false;
            } catch (\Throwable $exception) {
                // The locked state transition below is authoritative.
            }
            $activeScopeKey = "revision-integrity:{$lockedRevision->id}";
            if (DB::table('pdf_document_evidence_holds')
                ->where('active_scope_key', $activeScopeKey)
                ->lockForUpdate()
                ->exists()) {
                return false;
            }
            $preexistingRetirementHoldScopeKeys = DB::table('pdf_document_evidence_holds')
                ->where('document_id', $document->id)
                ->where('state', 'active')
                ->where('reason_bit', self::RETIREMENT_INTEGRITY)
                ->whereNotNull('active_scope_key')
                ->orderBy('id')
                ->pluck('active_scope_key')
                ->all();
            $preexistingRevisionIntegrityScopeKeys = array_values(array_filter(
                $preexistingRetirementHoldScopeKeys,
                fn (string $scopeKey): bool => str_starts_with($scopeKey, 'revision-integrity:'),
            ));
            $manifest = [
                'version' => 'published-revision-integrity-v1',
                'document_id' => (int) $document->id,
                'document_uuid' => $document->document_uuid,
                'document_integrity_bit_preexisting' => (((int) $document->integrity_hold_mask
                    & self::PUBLISHED_REVISION_INTEGRITY) !== 0),
                'revision_id' => (int) $lockedRevision->id,
                'revision_uuid' => $lockedRevision->revision_uuid,
                'revision_path' => $lockedRevision->file_path,
                'revision_sha256' => $lockedRevision->sha256_hash,
                'revision_size' => (int) $lockedRevision->file_size,
                'preexisting_retirement_hold_scope_keys' => $preexistingRetirementHoldScopeKeys,
                'preexisting_revision_integrity_scope_keys' => $preexistingRevisionIntegrityScopeKeys,
                'operations' => [],
                'executions' => [],
                'appearances' => [],
            ];
            /** @var PdfSigningOperation $operation */
            foreach ($scope['operations'] as $operation) {
                $preexisting = (((int) $operation->document_evidence_hold_mask
                    & self::RETIREMENT_INTEGRITY) !== 0);
                $manifest['operations'][] = [
                    'id' => (int) $operation->id,
                    'operation_uuid' => $operation->operation_uuid,
                    'preexisting' => $preexisting,
                ];
                $operation->update([
                    'document_evidence_hold_mask' => (int) $operation->document_evidence_hold_mask
                        | self::RETIREMENT_INTEGRITY,
                    'result_retirement_not_before' => null,
                    'result_retirement_authorized_at' => null,
                    'result_retirement_authorization_expires_at' => null,
                    'result_retirement_authorization_manifest' => null,
                    'result_retirement_authorization_hash' => null,
                ]);
            }
            /** @var PdfJavaSigningExecution $execution */
            foreach ($scope['executions'] as $execution) {
                if ($execution->result_path === null || $execution->result_integrity_state === 'retired') {
                    continue;
                }
                $preexisting = (((int) $execution->evidence_hold_mask & self::RETIREMENT_INTEGRITY) !== 0);
                $manifest['executions'][] = [
                    'id' => (int) $execution->id,
                    'operation_uuid' => $execution->operation_uuid,
                    'preexisting' => $preexisting,
                ];
                if (! $preexisting) {
                    $this->updateExecutionHold(
                        $execution,
                        (int) $execution->evidence_hold_mask | self::RETIREMENT_INTEGRITY,
                        'REVISION_INTEGRITY_HOLD_ADDED',
                    );
                }
            }
            /** @var PdfSignatureAppearanceArtifact $appearance */
            foreach ($scope['appearances'] as $appearance) {
                if ($appearance->file_path === null || $appearance->retirement_state === 'retired'
                    || $appearance->deleted_at !== null) {
                    continue;
                }
                $preexisting = (((int) $appearance->evidence_hold_mask & self::RETIREMENT_INTEGRITY) !== 0);
                $manifest['appearances'][] = [
                    'id' => (int) $appearance->id,
                    'appearance_uuid' => $appearance->appearance_uuid,
                    'preexisting' => $preexisting,
                ];
                if (! $preexisting) {
                    $appearance->update([
                        'evidence_hold_mask' => (int) $appearance->evidence_hold_mask
                            | self::RETIREMENT_INTEGRITY,
                        'evidence_hold_state' => 'active',
                        'hold_started_at' => $appearance->hold_started_at ?? now(),
                        'hold_released_at' => null,
                        'lock_version' => (int) $appearance->lock_version + 1,
                    ]);
                }
            }
            $manifestHash = hash('sha256', CanonicalJson::encode($manifest));
            DB::table('pdf_document_evidence_holds')->insert([
                'hold_uuid' => (string) Str::uuid(),
                'document_id' => $document->id,
                'reason_bit' => self::RETIREMENT_INTEGRITY,
                'reason_code' => $reasonCode,
                'state' => 'active',
                'active_scope_key' => $activeScopeKey,
                'target_manifest' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'target_manifest_hash' => $manifestHash,
                'installed_by_id' => null,
                'installed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $oldVersion = (int) $document->integrity_version;
            $oldMask = (int) $document->integrity_hold_mask;
            $newMask = $oldMask | self::PUBLISHED_REVISION_INTEGRITY;
            $lockedRevision->update(['integrity_state' => 'unavailable']);
            $document->update([
                'integrity_hold_mask' => $newMask,
                'integrity_state' => 'hold',
                'integrity_hold_started_at' => $document->integrity_hold_started_at ?? now(),
                'integrity_hold_released_at' => null,
                'integrity_version' => $oldVersion + 1,
            ]);
            $this->appendDocumentEvent(
                $document,
                $lockedRevision,
                'integrity_withdrawn',
                $reasonCode,
                null,
                $oldVersion,
                $oldVersion + 1,
                $oldMask,
                $newMask,
                $manifestHash,
            );

            return true;
        }, 3);
    }

    public function restore(PdfFile $revision, User $actor, string $reasonCode): PdfFile
    {
        if (preg_match('/^[A-Z0-9][A-Z0-9_:-]{2,95}$/', $reasonCode) !== 1
            || $revision->document_id === null) {
            throw new RuntimeException('PDF_REVISION_INTEGRITY_RESTORE_INPUT_INVALID');
        }
        $path = $this->files->verifiedAbsolutePath(
            $revision->file_path,
            $revision->sha256_hash,
            (int) $revision->file_size,
        );
        $this->assertRestoredRevision($revision, $path);

        return DB::transaction(function () use ($revision, $actor, $reasonCode): PdfFile {
            $scope = $this->lockScope((int) $revision->document_id);
            /** @var PdfDocument $document */
            $document = $scope['document'];
            /** @var PdfFile $lockedRevision */
            $lockedRevision = $scope['revisions']->firstWhere('id', $revision->id);
            if (! $lockedRevision instanceof PdfFile) {
                throw new RuntimeException('PDF_REVISION_INTEGRITY_TARGET_CHANGED');
            }
            $this->files->verifiedAbsolutePath(
                $lockedRevision->file_path,
                $lockedRevision->sha256_hash,
                (int) $lockedRevision->file_size,
            );
            $hold = DB::table('pdf_document_evidence_holds')
                ->where('active_scope_key', "revision-integrity:{$lockedRevision->id}")
                ->lockForUpdate()
                ->first();
            if ($hold === null) {
                throw new RuntimeException('PDF_REVISION_INTEGRITY_HOLD_NOT_ACTIVE');
            }
            $manifest = json_decode((string) $hold->target_manifest, true, flags: JSON_THROW_ON_ERROR);
            if (! hash_equals(
                (string) $hold->target_manifest_hash,
                hash('sha256', CanonicalJson::encode($manifest)),
            ) || ($manifest['revision_uuid'] ?? null) !== $lockedRevision->revision_uuid
                || ($manifest['revision_sha256'] ?? null) !== $lockedRevision->sha256_hash) {
                throw new RuntimeException('PDF_REVISION_INTEGRITY_HOLD_MANIFEST_BREACHED');
            }
            $manifestOperationIds = collect($manifest['operations'] ?? [])
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $currentOperationIds = $scope['operations']->pluck('id')
                ->map(fn ($id): int => (int) $id)->all();
            if ($manifestOperationIds !== $currentOperationIds) {
                throw new RuntimeException('PDF_REVISION_INTEGRITY_SCOPE_CHANGED');
            }
            $otherActiveRetirementHold = DB::table('pdf_document_evidence_holds')
                ->where('document_id', $document->id)
                ->where('state', 'active')
                ->where('reason_bit', self::RETIREMENT_INTEGRITY)
                ->where('id', '!=', $hold->id)
                ->lockForUpdate()
                ->exists();
            $otherActiveRevisionIntegrityHold = DB::table('pdf_document_evidence_holds')
                ->where('document_id', $document->id)
                ->where('state', 'active')
                ->where('active_scope_key', 'like', 'revision-integrity:%')
                ->where('id', '!=', $hold->id)
                ->lockForUpdate()
                ->exists();
            $recordedRetirementOwners = array_values(array_filter(
                $manifest['preexisting_retirement_hold_scope_keys'] ?? [],
                'is_string',
            ));
            $recordedRevisionOwners = array_values(array_filter(
                $manifest['preexisting_revision_integrity_scope_keys'] ?? [],
                'is_string',
            ));
            $recordedRetirementOwnerStillActive = $recordedRetirementOwners !== []
                && DB::table('pdf_document_evidence_holds')
                    ->where('document_id', $document->id)
                    ->where('state', 'active')
                    ->whereIn('active_scope_key', $recordedRetirementOwners)
                    ->lockForUpdate()
                    ->exists();
            $recordedRevisionOwnerStillActive = $recordedRevisionOwners !== []
                && DB::table('pdf_document_evidence_holds')
                    ->where('document_id', $document->id)
                    ->where('state', 'active')
                    ->whereIn('active_scope_key', $recordedRevisionOwners)
                    ->lockForUpdate()
                    ->exists();
            $operations = $scope['operations']->keyBy('id');
            foreach ($manifest['operations'] as $target) {
                $operation = $operations->get((int) $target['id']);
                if (! $operation instanceof PdfSigningOperation
                    || $operation->operation_uuid !== $target['operation_uuid']) {
                    throw new RuntimeException('PDF_REVISION_INTEGRITY_SCOPE_CHANGED');
                }
                $preexistingMustRemain = (bool) $target['preexisting']
                    && ($recordedRetirementOwners === [] || $recordedRetirementOwnerStillActive);
                if (! $otherActiveRetirementHold && ! $preexistingMustRemain) {
                    $operation->update([
                        'document_evidence_hold_mask' => (int) $operation->document_evidence_hold_mask
                            & ~self::RETIREMENT_INTEGRITY,
                    ]);
                }
            }
            $executions = $scope['executions']->keyBy('id');
            foreach ($manifest['executions'] as $target) {
                $execution = $executions->get((int) $target['id']);
                if (! $execution instanceof PdfJavaSigningExecution
                    || $execution->operation_uuid !== $target['operation_uuid']) {
                    throw new RuntimeException('PDF_REVISION_INTEGRITY_SCOPE_CHANGED');
                }
                $preexistingMustRemain = (bool) $target['preexisting']
                    && ($recordedRetirementOwners === [] || $recordedRetirementOwnerStillActive);
                if (! $otherActiveRetirementHold && ! $preexistingMustRemain
                    && $execution->retirement_phase !== 'none') {
                    throw new RuntimeException('PDF_REVISION_INTEGRITY_RETIREMENT_NOT_RESTORED');
                }
                if (! $otherActiveRetirementHold && ! $preexistingMustRemain) {
                    $this->updateExecutionHold(
                        $execution,
                        (int) $execution->evidence_hold_mask & ~self::RETIREMENT_INTEGRITY,
                        'REVISION_INTEGRITY_HOLD_RELEASED',
                    );
                }
            }
            $appearances = $scope['appearances']->keyBy('id');
            foreach ($manifest['appearances'] as $target) {
                $appearance = $appearances->get((int) $target['id']);
                if (! $appearance instanceof PdfSignatureAppearanceArtifact
                    || $appearance->appearance_uuid !== $target['appearance_uuid']) {
                    throw new RuntimeException('PDF_REVISION_INTEGRITY_SCOPE_CHANGED');
                }
                $preexistingMustRemain = (bool) $target['preexisting']
                    && ($recordedRetirementOwners === [] || $recordedRetirementOwnerStillActive);
                if (! $otherActiveRetirementHold && ! $preexistingMustRemain
                    && $appearance->retirement_state !== 'none') {
                    throw new RuntimeException('PDF_REVISION_INTEGRITY_RETIREMENT_NOT_RESTORED');
                }
                if (! $otherActiveRetirementHold && ! $preexistingMustRemain) {
                    $newMask = (int) $appearance->evidence_hold_mask & ~self::RETIREMENT_INTEGRITY;
                    $appearance->update([
                        'evidence_hold_mask' => $newMask,
                        'evidence_hold_state' => $newMask === 0 ? 'none' : 'active',
                        'hold_released_at' => $newMask === 0 ? now() : null,
                        'lock_version' => (int) $appearance->lock_version + 1,
                    ]);
                }
            }
            DB::table('pdf_document_evidence_holds')->where('id', $hold->id)->update([
                'state' => 'released',
                'active_scope_key' => null,
                'released_by_id' => $actor->id,
                'release_reason_code' => $reasonCode,
                'released_at' => now(),
                'updated_at' => now(),
            ]);
            $oldVersion = (int) $document->integrity_version;
            $oldMask = (int) $document->integrity_hold_mask;
            $documentPreexistingMustRemain = (bool) ($manifest['document_integrity_bit_preexisting'] ?? false)
                && ($recordedRevisionOwners === [] || $recordedRevisionOwnerStillActive);
            $newMask = ($otherActiveRevisionIntegrityHold || $documentPreexistingMustRemain)
                ? $oldMask
                : $oldMask & ~self::PUBLISHED_REVISION_INTEGRITY;
            $lockedRevision->update(['integrity_state' => 'ready']);
            $document->update([
                'integrity_hold_mask' => $newMask,
                'integrity_state' => $newMask === 0 ? 'ok' : 'hold',
                'integrity_hold_released_at' => $newMask === 0 ? now() : null,
                'integrity_version' => $oldVersion + 1,
            ]);
            $this->appendDocumentEvent(
                $document,
                $lockedRevision,
                'integrity_restored',
                $reasonCode,
                $actor,
                $oldVersion,
                $oldVersion + 1,
                $oldMask,
                $newMask,
                (string) $hold->target_manifest_hash,
            );

            return $lockedRevision->refresh();
        }, 3);
    }

    /** @return array{document: PdfDocument, operations: Collection, executions: Collection, revisions: Collection, appearances: Collection} */
    private function lockScope(int $documentId): array
    {
        $operationIds = PdfSigningOperation::query()->where('document_id', $documentId)
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $operations = PdfSigningOperation::query()->whereIn('id', $operationIds)
            ->orderBy('id')->lockForUpdate()->get();
        $executions = PdfJavaSigningExecution::query()
            ->whereIn('operation_uuid', $operations->pluck('operation_uuid'))
            ->orderBy('operation_uuid')->lockForUpdate()->get();
        $document = PdfDocument::query()->lockForUpdate()->findOrFail($documentId);
        $workflowIds = DB::table('pdf_signing_workflows')->where('document_id', $documentId)
            ->orderBy('id')->pluck('id');
        DB::table('pdf_signing_workflows')->whereIn('id', $workflowIds)
            ->orderBy('id')->lockForUpdate()->get();
        $requestIds = DB::table('pdf_signing_requests')->whereIn('workflow_id', $workflowIds)
            ->orderBy('id')->pluck('id');
        DB::table('pdf_signing_requests')->whereIn('id', $requestIds)
            ->orderBy('id')->lockForUpdate()->get();
        $revisions = PdfFile::query()->where('document_id', $documentId)
            ->orderBy('id')->lockForUpdate()->get();
        $appearances = PdfSignatureAppearanceArtifact::query()
            ->where(function ($query) use ($requestIds, $operationIds): void {
                $query->whereIn('request_id', $requestIds)->orWhereIn('claimed_by_operation_id', $operationIds);
            })->orderBy('id')->lockForUpdate()->get();
        $revalidated = PdfSigningOperation::query()->where('document_id', $documentId)
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($operationIds !== $revalidated) {
            throw new RuntimeException('PDF_REVISION_INTEGRITY_SCOPE_CHANGED');
        }

        return compact('document', 'operations', 'executions', 'revisions', 'appearances');
    }

    private function updateExecutionHold(
        PdfJavaSigningExecution $execution,
        int $newMask,
        string $eventType,
    ): void {
        $oldVersion = (int) $execution->lock_version;
        $now = now();
        $execution->update([
            'evidence_hold_mask' => $newMask,
            'evidence_hold_state' => $newMask === 0 ? 'none' : 'active',
            'lock_version' => $oldVersion + 1,
        ]);
        $event = [
            'operation_uuid' => $execution->operation_uuid,
            'attempt_number' => (int) $execution->attempt_number,
            'event_type' => $eventType,
            'old_state' => $execution->state,
            'new_state' => $execution->state,
            'old_retirement_phase' => $execution->retirement_phase,
            'new_retirement_phase' => $execution->retirement_phase,
            'old_lock_version' => $oldVersion,
            'new_lock_version' => $oldVersion + 1,
            'authorized_lease_epoch' => $execution->authorized_lease_epoch,
            'retirement_epoch' => $execution->retirement_epoch,
            'event_at' => $now->toISOString(),
        ];
        DB::table('pdf_java_signing_execution_events')->insert([
            ...$event,
            'event_at' => $now,
            'event_hash' => hash('sha256', CanonicalJson::encode($event)),
        ]);
    }

    private function assertRestoredRevision(PdfFile $revision, string $path): void
    {
        if ($revision->signed_at === null) {
            $inspection = $this->renderer->inspectSignaturePdf($path);
            if ((int) ($inspection['signatureCount'] ?? -1) !== 0) {
                throw new RuntimeException('PDF_REVISION_RESTORE_SIGNATURE_STATE_INVALID');
            }

            return;
        }
        $verification = $this->renderer->verifySignaturePdf($path);
        if (($verification['documentCurrentState'] ?? null) !== 'valid'
            || count($verification['signatures'] ?? []) < 1) {
            throw new RuntimeException('PDF_REVISION_RESTORE_SIGNATURE_STATE_INVALID');
        }
    }

    private function appendDocumentEvent(
        PdfDocument $document,
        PdfFile $revision,
        string $eventType,
        string $reasonCode,
        ?User $actor,
        int $oldVersion,
        int $newVersion,
        int $oldMask,
        int $newMask,
        string $manifestHash,
    ): void {
        $audit = [
            'document_uuid' => $document->document_uuid,
            'event_type' => $eventType,
            'manifest_hash' => $manifestHash,
            'new_integrity_hold_mask' => $newMask,
            'new_integrity_version' => $newVersion,
            'old_integrity_hold_mask' => $oldMask,
            'old_integrity_version' => $oldVersion,
            'reason_code' => $reasonCode,
            'revision_uuid' => $revision->revision_uuid,
        ];
        DB::table('pdf_document_publication_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'revision_id' => $revision->id,
            'event_type' => $eventType,
            'reason_code' => $reasonCode,
            'actor_user_id' => $actor?->id,
            'occurred_at' => now(),
            'audit_context_hash' => hash('sha256', CanonicalJson::encode($audit)),
            'previous_published_revision_id' => $document->published_revision_id,
            'old_integrity_version' => $oldVersion,
            'new_integrity_version' => $newVersion,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

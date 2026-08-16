<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfJavaSigningExecution;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningOperation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PdfDocumentEvidenceHoldService
{
    public const MANUAL_REVIEW = 1;

    public const IRREVERSIBLE_FAILURE = 2;

    public const QUARANTINE = 4;

    public const RETIREMENT_INTEGRITY = 8;

    public const LEGAL_HOLD = 16;

    private const ALLOWED_BITS = [
        self::MANUAL_REVIEW,
        self::IRREVERSIBLE_FAILURE,
        self::QUARANTINE,
        self::RETIREMENT_INTEGRITY,
        self::LEGAL_HOLD,
    ];

    public function __construct(private readonly PdfRendererClient $renderer) {}

    public function install(
        PdfDocument $document,
        int $reasonBit,
        string $reasonCode,
        User $actor,
        ?Carbon $legalHoldUntil = null,
    ): PdfDocument {
        $this->validateInput($reasonBit, $reasonCode, $legalHoldUntil);

        return DB::transaction(function () use (
            $document,
            $reasonBit,
            $reasonCode,
            $actor,
            $legalHoldUntil,
        ): PdfDocument {
            $scope = $this->lockScope($document->id);
            /** @var PdfDocument $lockedDocument */
            $lockedDocument = $scope['document'];
            $activeScopeKey = "document:{$lockedDocument->id}:reason:{$reasonBit}";
            if (DB::table('pdf_document_evidence_holds')
                ->where('active_scope_key', $activeScopeKey)
                ->lockForUpdate()
                ->exists()) {
                throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_ALREADY_ACTIVE');
            }

            $preexistingReasonOwnerScopeKeys = DB::table('pdf_document_evidence_holds')
                ->where('document_id', $lockedDocument->id)
                ->where('state', 'active')
                ->where('reason_bit', $reasonBit)
                ->whereNotNull('active_scope_key')
                ->orderBy('id')
                ->pluck('active_scope_key')
                ->all();
            $preexistingDocumentOwnerScopeKeys = array_values(array_filter(
                $preexistingReasonOwnerScopeKeys,
                fn (string $scopeKey): bool => str_starts_with($scopeKey, "document:{$lockedDocument->id}:reason:"),
            ));

            $manifest = [
                'document_bit_preexisting' => (((int) $lockedDocument->evidence_hold_mask & $reasonBit) !== 0),
                'document_legal_hold_until_preexisting' => $lockedDocument->legal_hold_until?->toISOString(),
                'document_uuid' => $lockedDocument->document_uuid,
                'executions' => [],
                'appearances' => [],
                'operation_ids' => $scope['operations']->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'operation_fences' => [],
                'preexisting_document_owner_scope_keys' => $preexistingDocumentOwnerScopeKeys,
                'preexisting_reason_owner_scope_keys' => $preexistingReasonOwnerScopeKeys,
                'reason_bit' => $reasonBit,
                'version' => 'document-evidence-hold-v1',
            ];

            /** @var PdfSigningOperation $operation */
            foreach ($scope['operations'] as $operation) {
                $preexisting = (((int) $operation->document_evidence_hold_mask & $reasonBit) !== 0);
                $manifest['operation_fences'][] = [
                    'id' => (int) $operation->id,
                    'operation_uuid' => $operation->operation_uuid,
                    'preexisting' => $preexisting,
                ];
                $newMask = (int) $operation->document_evidence_hold_mask | $reasonBit;
                if ($newMask !== (int) $operation->document_evidence_hold_mask) {
                    $operation->update(['document_evidence_hold_mask' => $newMask]);
                }
                $this->clearRetirementAuthorization($operation);
            }

            /** @var PdfJavaSigningExecution $execution */
            foreach ($scope['executions'] as $execution) {
                if ($execution->result_integrity_state === 'retired'
                    || $execution->retirement_phase === 'retired'
                    || $execution->bytes_deleted_at !== null) {
                    throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_ALREADY_RETIRED');
                }
                if ($execution->result_path === null) {
                    continue;
                }
                $operation = $scope['operations']->firstWhere('operation_uuid', $execution->operation_uuid);
                if (! $operation instanceof PdfSigningOperation) {
                    throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_TARGET_CHANGED');
                }
                $this->assertExecutionEvidenceAvailable($execution, $operation);
                $preexisting = (((int) $execution->evidence_hold_mask & $reasonBit) !== 0);
                $manifest['executions'][] = [
                    'id' => (int) $execution->id,
                    'operation_uuid' => $execution->operation_uuid,
                    'preexisting' => $preexisting,
                    'legal_hold_until_preexisting' => $execution->legal_hold_until?->toISOString(),
                    'retirement_epoch' => (int) $execution->retirement_epoch,
                ];
                $newMask = (int) $execution->evidence_hold_mask | $reasonBit;
                $newLegalUntil = $reasonBit === self::LEGAL_HOLD
                    ? $this->laterOf($execution->legal_hold_until, $legalHoldUntil)
                    : $execution->legal_hold_until;
                if ($newMask !== (int) $execution->evidence_hold_mask
                    || ! $this->sameInstant($newLegalUntil, $execution->legal_hold_until)) {
                    $this->updateExecutionHold($execution, $newMask, $newLegalUntil, 'DOCUMENT_EVIDENCE_HOLD_ADDED');
                }
            }

            /** @var PdfSignatureAppearanceArtifact $appearance */
            foreach ($scope['appearances'] as $appearance) {
                if ($appearance->retirement_state === 'retired' || $appearance->deleted_at !== null) {
                    throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_ALREADY_RETIRED');
                }
                if ($appearance->file_path === null) {
                    continue;
                }
                $preexisting = (((int) $appearance->evidence_hold_mask & $reasonBit) !== 0);
                $manifest['appearances'][] = [
                    'id' => (int) $appearance->id,
                    'appearance_uuid' => $appearance->appearance_uuid,
                    'preexisting' => $preexisting,
                    'legal_hold_until_preexisting' => $appearance->legal_hold_until?->toISOString(),
                    'retirement_epoch' => (int) $appearance->retirement_epoch,
                ];
                $newMask = (int) $appearance->evidence_hold_mask | $reasonBit;
                $newLegalUntil = $reasonBit === self::LEGAL_HOLD
                    ? $this->laterOf($appearance->legal_hold_until, $legalHoldUntil)
                    : $appearance->legal_hold_until;
                if ($newMask !== (int) $appearance->evidence_hold_mask
                    || ! $this->sameInstant($newLegalUntil, $appearance->legal_hold_until)) {
                    $appearance->update([
                        'evidence_hold_mask' => $newMask,
                        'evidence_hold_state' => 'active',
                        'hold_started_at' => (int) $appearance->evidence_hold_mask === 0
                            ? now()
                            : ($appearance->hold_started_at ?? now()),
                        'hold_released_at' => null,
                        'legal_hold_until' => $newLegalUntil,
                        'lock_version' => (int) $appearance->lock_version + 1,
                    ]);
                }
            }

            $manifestHash = hash('sha256', CanonicalJson::encode($manifest));
            DB::table('pdf_document_evidence_holds')->insert([
                'hold_uuid' => (string) Str::uuid(),
                'document_id' => $lockedDocument->id,
                'reason_bit' => $reasonBit,
                'reason_code' => $reasonCode,
                'state' => 'active',
                'active_scope_key' => $activeScopeKey,
                'target_manifest' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'target_manifest_hash' => $manifestHash,
                'legal_hold_until' => $legalHoldUntil,
                'installed_by_id' => $actor->id,
                'installed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $oldMask = (int) $lockedDocument->evidence_hold_mask;
            $oldVersion = (int) $lockedDocument->integrity_version;
            $newMask = $oldMask | $reasonBit;
            $lockedDocument->update([
                'evidence_hold_mask' => $newMask,
                'evidence_hold_state' => 'active',
                'evidence_hold_started_at' => $lockedDocument->evidence_hold_started_at ?? now(),
                'evidence_hold_released_at' => null,
                'legal_hold_until' => $reasonBit === self::LEGAL_HOLD
                    ? $this->laterOf($lockedDocument->legal_hold_until, $legalHoldUntil)
                    : $lockedDocument->legal_hold_until,
                'integrity_version' => $oldVersion + 1,
            ]);
            $this->appendDocumentEvent(
                $lockedDocument,
                'evidence_hold_added',
                $reasonCode,
                $actor,
                $oldVersion,
                $oldVersion + 1,
                $oldMask,
                $newMask,
                $manifestHash,
            );

            return $lockedDocument->refresh();
        }, 3);
    }

    public function release(
        PdfDocument $document,
        int $reasonBit,
        string $reasonCode,
        User $actor,
    ): PdfDocument {
        $this->validateInput($reasonBit, $reasonCode, null, releasing: true);

        return DB::transaction(function () use ($document, $reasonBit, $reasonCode, $actor): PdfDocument {
            $scope = $this->lockScope($document->id);
            /** @var PdfDocument $lockedDocument */
            $lockedDocument = $scope['document'];
            $activeScopeKey = "document:{$lockedDocument->id}:reason:{$reasonBit}";
            $hold = DB::table('pdf_document_evidence_holds')
                ->where('active_scope_key', $activeScopeKey)
                ->lockForUpdate()
                ->first();
            if ($hold === null) {
                throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_NOT_ACTIVE');
            }
            $manifest = json_decode((string) $hold->target_manifest, true, flags: JSON_THROW_ON_ERROR);
            if (! hash_equals(
                (string) $hold->target_manifest_hash,
                hash('sha256', CanonicalJson::encode($manifest)),
            )) {
                throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_MANIFEST_BREACHED');
            }
            $manifestOperationIds = array_map('intval', $manifest['operation_ids'] ?? []);
            $currentOperationIds = $scope['operations']->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($manifestOperationIds !== $currentOperationIds) {
                throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_SCOPE_CHANGED');
            }

            $otherActiveReasonOwner = DB::table('pdf_document_evidence_holds')
                ->where('document_id', $lockedDocument->id)
                ->where('state', 'active')
                ->where('reason_bit', $reasonBit)
                ->where('id', '!=', $hold->id)
                ->lockForUpdate()
                ->exists();
            $preexistingReasonOwnerScopeKeys = array_values(array_filter(
                $manifest['preexisting_reason_owner_scope_keys'] ?? [],
                'is_string',
            ));
            $preexistingReasonOwnerStillActive = $preexistingReasonOwnerScopeKeys !== []
                && DB::table('pdf_document_evidence_holds')
                    ->where('document_id', $lockedDocument->id)
                    ->where('state', 'active')
                    ->whereIn('active_scope_key', $preexistingReasonOwnerScopeKeys)
                    ->lockForUpdate()
                    ->exists();
            $preexistingDocumentOwnerScopeKeys = array_values(array_filter(
                $manifest['preexisting_document_owner_scope_keys'] ?? [],
                'is_string',
            ));
            $preexistingDocumentOwnerStillActive = $preexistingDocumentOwnerScopeKeys !== []
                && DB::table('pdf_document_evidence_holds')
                    ->where('document_id', $lockedDocument->id)
                    ->where('state', 'active')
                    ->whereIn('active_scope_key', $preexistingDocumentOwnerScopeKeys)
                    ->lockForUpdate()
                    ->exists();

            /** @var Collection<int, PdfSigningOperation> $operations */
            $operations = $scope['operations']->keyBy('id');
            foreach ($manifest['operation_fences'] ?? [] as $target) {
                /** @var PdfSigningOperation|null $operation */
                $operation = $operations->get((int) $target['id']);
                if ($operation === null || $operation->operation_uuid !== $target['operation_uuid']) {
                    throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_TARGET_CHANGED');
                }
                $preexistingMustRemain = (bool) $target['preexisting']
                    && ($preexistingReasonOwnerScopeKeys === [] || $preexistingReasonOwnerStillActive);
                if (! $otherActiveReasonOwner && ! $preexistingMustRemain) {
                    $operation->update([
                        'document_evidence_hold_mask' => (int) $operation->document_evidence_hold_mask & ~$reasonBit,
                    ]);
                }
            }

            /** @var Collection<int, PdfJavaSigningExecution> $executions */
            $executions = $scope['executions']->keyBy('id');
            foreach ($manifest['executions'] as $target) {
                /** @var PdfJavaSigningExecution|null $execution */
                $execution = $executions->get((int) $target['id']);
                if ($execution === null || $execution->operation_uuid !== $target['operation_uuid']) {
                    throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_TARGET_CHANGED');
                }
                if ($execution->retirement_phase !== 'none') {
                    throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_RETIREMENT_NOT_RESTORED');
                }
                $preexistingMustRemain = (bool) $target['preexisting']
                    && ($preexistingReasonOwnerScopeKeys === [] || $preexistingReasonOwnerStillActive);
                $newMask = ($otherActiveReasonOwner || $preexistingMustRemain)
                    ? (int) $execution->evidence_hold_mask
                    : (int) $execution->evidence_hold_mask & ~$reasonBit;
                $newLegalUntil = $reasonBit === self::LEGAL_HOLD
                    ? $this->parseManifestInstant($target['legal_hold_until_preexisting'] ?? null)
                    : $execution->legal_hold_until;
                if ($newMask !== (int) $execution->evidence_hold_mask
                    || ! $this->sameInstant($newLegalUntil, $execution->legal_hold_until)) {
                    $this->updateExecutionHold(
                        $execution,
                        $newMask,
                        $newLegalUntil,
                        'DOCUMENT_EVIDENCE_HOLD_RELEASED',
                    );
                }
            }

            /** @var Collection<int, PdfSignatureAppearanceArtifact> $appearances */
            $appearances = $scope['appearances']->keyBy('id');
            foreach ($manifest['appearances'] as $target) {
                /** @var PdfSignatureAppearanceArtifact|null $appearance */
                $appearance = $appearances->get((int) $target['id']);
                if ($appearance === null || $appearance->appearance_uuid !== $target['appearance_uuid']) {
                    throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_TARGET_CHANGED');
                }
                if ($appearance->retirement_state !== 'none') {
                    throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_RETIREMENT_NOT_RESTORED');
                }
                $preexistingMustRemain = (bool) $target['preexisting']
                    && ($preexistingReasonOwnerScopeKeys === [] || $preexistingReasonOwnerStillActive);
                $newMask = ($otherActiveReasonOwner || $preexistingMustRemain)
                    ? (int) $appearance->evidence_hold_mask
                    : (int) $appearance->evidence_hold_mask & ~$reasonBit;
                $newLegalUntil = $reasonBit === self::LEGAL_HOLD
                    ? $this->parseManifestInstant($target['legal_hold_until_preexisting'] ?? null)
                    : $appearance->legal_hold_until;
                if ($newMask !== (int) $appearance->evidence_hold_mask
                    || ! $this->sameInstant($newLegalUntil, $appearance->legal_hold_until)) {
                    $appearance->update([
                        'evidence_hold_mask' => $newMask,
                        'evidence_hold_state' => $newMask === 0 ? 'none' : 'active',
                        'hold_released_at' => $newMask === 0 ? now() : null,
                        'retention_until' => $newMask === 0 ? now()->addDay() : $appearance->retention_until,
                        'legal_hold_until' => $newLegalUntil,
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
            $oldMask = (int) $lockedDocument->evidence_hold_mask;
            $oldVersion = (int) $lockedDocument->integrity_version;
            $documentPreexistingMustRemain = (bool) ($manifest['document_bit_preexisting'] ?? false)
                && ($preexistingDocumentOwnerScopeKeys === [] || $preexistingDocumentOwnerStillActive);
            $newMask = $documentPreexistingMustRemain
                ? $oldMask
                : $oldMask & ~$reasonBit;
            $newLegalUntil = $reasonBit === self::LEGAL_HOLD
                ? $this->parseManifestInstant($manifest['document_legal_hold_until_preexisting'] ?? null)
                : $lockedDocument->legal_hold_until;
            $lockedDocument->update([
                'evidence_hold_mask' => $newMask,
                'evidence_hold_state' => $newMask === 0 ? 'none' : 'active',
                'evidence_hold_released_at' => $newMask === 0 ? now() : null,
                'legal_hold_until' => $newLegalUntil,
                'integrity_version' => $oldVersion + 1,
            ]);
            $this->appendDocumentEvent(
                $lockedDocument,
                'evidence_hold_released',
                $reasonCode,
                $actor,
                $oldVersion,
                $oldVersion + 1,
                $oldMask,
                $newMask,
                (string) $hold->target_manifest_hash,
            );

            return $lockedDocument->refresh();
        }, 3);
    }

    /** @return array{document: PdfDocument, operations: Collection, executions: Collection, appearances: Collection} */
    private function lockScope(int $documentId): array
    {
        $snapshotIds = PdfSigningOperation::query()
            ->where('document_id', $documentId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $operations = PdfSigningOperation::query()
            ->whereIn('id', $snapshotIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $operationUuids = $operations->pluck('operation_uuid')->all();
        $executions = PdfJavaSigningExecution::query()
            ->whereIn('operation_uuid', $operationUuids)
            ->orderBy('operation_uuid')
            ->lockForUpdate()
            ->get();
        $document = PdfDocument::query()->lockForUpdate()->findOrFail($documentId);
        $workflowIds = DB::table('pdf_signing_workflows')
            ->where('document_id', $documentId)
            ->orderBy('id')
            ->pluck('id');
        DB::table('pdf_signing_workflows')->whereIn('id', $workflowIds)->orderBy('id')->lockForUpdate()->get();
        $requestIds = DB::table('pdf_signing_requests')
            ->whereIn('workflow_id', $workflowIds)
            ->orderBy('id')
            ->pluck('id');
        DB::table('pdf_signing_requests')->whereIn('id', $requestIds)->orderBy('id')->lockForUpdate()->get();
        DB::table('pdf_files')->where('document_id', $documentId)->orderBy('id')->lockForUpdate()->get();
        $appearances = PdfSignatureAppearanceArtifact::query()
            ->where(function ($query) use ($requestIds, $snapshotIds): void {
                $query->whereIn('request_id', $requestIds)
                    ->orWhereIn('claimed_by_operation_id', $snapshotIds);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $revalidatedIds = PdfSigningOperation::query()
            ->where('document_id', $documentId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($snapshotIds !== $revalidatedIds) {
            throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_HOLD_SCOPE_CHANGED');
        }

        return compact('document', 'operations', 'executions', 'appearances');
    }

    private function updateExecutionHold(
        PdfJavaSigningExecution $execution,
        int $newMask,
        ?Carbon $legalHoldUntil,
        string $eventType,
    ): void {
        $oldVersion = (int) $execution->lock_version;
        $now = now();
        $execution->update([
            'evidence_hold_mask' => $newMask,
            'evidence_hold_state' => $newMask === 0 ? 'none' : 'active',
            'legal_hold_until' => $legalHoldUntil,
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

    private function assertExecutionEvidenceAvailable(
        PdfJavaSigningExecution $execution,
        PdfSigningOperation $operation,
    ): void {
        if (in_array($execution->result_integrity_state, ['missing', 'breached'], true)) {
            throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_INTEGRITY_UNAVAILABLE');
        }
        if (! in_array($execution->result_integrity_state, ['available', 'retiring'], true)
            || ! in_array($execution->retirement_phase, ['none', 'stage_intent', 'staged', 'purge_intent'], true)
            || ! is_string($execution->result_sha256)
            || ! preg_match('/^[0-9a-f]{64}$/', $execution->result_sha256)
            || (int) $execution->result_size <= 0) {
            throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_SNAPSHOT_INVALID');
        }
        $inspection = $this->renderer->inspectSigningRetirementEvidence(
            $execution->operation_uuid,
            (int) $execution->retirement_epoch,
            $execution->retirement_phase,
            $execution->result_sha256,
            (int) $execution->result_size,
            [
                'operationUuid' => $operation->operation_uuid,
                'leaseEpoch' => (int) $operation->lease_epoch,
                'operationInputManifestHash' => $operation->operation_input_manifest_hash,
                'inputFingerprint' => $operation->input_fingerprint,
                'policyHash' => $operation->policy_hash,
            ],
        );
        $state = (string) ($inspection['state'] ?? '');
        $identityMatches = ($inspection['operationUuid'] ?? null) === $execution->operation_uuid
            && (int) ($inspection['retirementEpoch'] ?? -1) === (int) $execution->retirement_epoch
            && ($inspection['retirementPhase'] ?? null) === $execution->retirement_phase
            && ($inspection['expectedSha256'] ?? null) === $execution->result_sha256
            && (int) ($inspection['expectedSize'] ?? -1) === (int) $execution->result_size;
        $allowedStates = $execution->retirement_phase === 'none'
            ? ['canonical']
            : ['canonical', 'staged'];
        if (! $identityMatches || ! in_array($state, $allowedStates, true)) {
            if ($state === 'missing' && $execution->retirement_phase === 'purge_intent') {
                throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_ALREADY_RETIRED');
            }
            throw new ConflictHttpException('PDF_DOCUMENT_EVIDENCE_INTEGRITY_UNAVAILABLE');
        }
    }

    private function clearRetirementAuthorization(PdfSigningOperation $operation): void
    {
        if ($operation->result_retirement_authorization_hash === null) {
            return;
        }
        $operation->update([
            'result_retirement_not_before' => null,
            'result_retirement_authorized_at' => null,
            'result_retirement_authorization_expires_at' => null,
            'result_retirement_authorization_manifest' => null,
            'result_retirement_authorization_hash' => null,
        ]);
    }

    private function appendDocumentEvent(
        PdfDocument $document,
        string $eventType,
        string $reasonCode,
        User $actor,
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
            'new_evidence_hold_mask' => $newMask,
            'new_integrity_version' => $newVersion,
            'old_evidence_hold_mask' => $oldMask,
            'old_integrity_version' => $oldVersion,
            'reason_code' => $reasonCode,
        ];
        DB::table('pdf_document_publication_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'revision_id' => null,
            'event_type' => $eventType,
            'reason_code' => $reasonCode,
            'actor_user_id' => $actor->id,
            'occurred_at' => now(),
            'audit_context_hash' => hash('sha256', CanonicalJson::encode($audit)),
            'previous_published_revision_id' => $document->published_revision_id,
            'old_integrity_version' => $oldVersion,
            'new_integrity_version' => $newVersion,
            'old_evidence_hold_mask' => $oldMask,
            'new_evidence_hold_mask' => $newMask,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validateInput(
        int $reasonBit,
        string $reasonCode,
        ?Carbon $legalHoldUntil,
        bool $releasing = false,
    ): void {
        if (! in_array($reasonBit, self::ALLOWED_BITS, true)
            || preg_match('/^[A-Z0-9][A-Z0-9_:-]{2,95}$/', $reasonCode) !== 1
            || (! $releasing && $reasonBit === self::LEGAL_HOLD
                && ($legalHoldUntil === null || ! $legalHoldUntil->isFuture()))
            || ($reasonBit !== self::LEGAL_HOLD && $legalHoldUntil !== null)) {
            throw new \InvalidArgumentException('PDF_DOCUMENT_EVIDENCE_HOLD_INPUT_INVALID');
        }
    }

    private function laterOf(?Carbon $left, ?Carbon $right): ?Carbon
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return $left->greaterThan($right) ? $left : $right;
    }

    private function sameInstant(?Carbon $left, ?Carbon $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return $left->equalTo($right);
    }

    private function parseManifestInstant(mixed $value): ?Carbon
    {
        return $value === null
            ? null
            : Carbon::parse((string) $value)->setTimezone((string) config('app.timezone'));
    }
}

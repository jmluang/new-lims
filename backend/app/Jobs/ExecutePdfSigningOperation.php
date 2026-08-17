<?php

namespace App\Jobs;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfJavaSigningExecution;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningAct;
use App\Models\PdfSigningField;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningPolicyVersion;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningSlot;
use App\Models\PdfSigningWorkflow;
use App\Models\User;
use App\Services\Pdf\CanonicalJson;
use App\Services\Pdf\PdfImmutableFileStore;
use App\Services\Pdf\PdfRendererClient;
use App\Services\Pdf\PdfRendererHttpException;
use App\Services\Pdf\PdfRevisionService;
use App\Services\Pdf\PdfSigningNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ExecutePdfSigningOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [2, 5, 15];

    private const MANUAL_REVIEW_HOLD = 1;

    private const IRREVERSIBLE_FAILURE_HOLD = 2;

    private const QUARANTINE_HOLD = 4;

    private const DOCUMENT_MANUAL_REVIEW_HOLD = 8;

    public function __construct(public readonly string $operationUuid) {}

    public function handle(
        PdfRendererClient $renderer,
        PdfImmutableFileStore $files,
        PdfRevisionService $revisions,
    ): void {
        $operation = $this->activateOrLoad();

        if ($this->isTerminal($operation->state)) {
            return;
        }

        if ($operation->state === 'promoted' && $operation->stage === 'committing') {
            $this->complete($operation, [
                'state' => 'completed',
                'resultSha256' => $operation->result_sha256,
                'resultSize' => (int) $operation->result_size,
            ], $renderer, $files, $revisions);

            return;
        }

        if ($operation->stage === 'java_call' && $operation->java_request_started_at === null) {
            $requestStart = $this->markRequestStarted($operation);
            if ($requestStart['should_submit']) {
                $this->submitOnce($requestStart['operation'], $renderer, $files, $revisions);
            } elseif (! $this->isTerminal($requestStart['operation']->state)) {
                $this->pollOnly($requestStart['operation'], $renderer, $files, $revisions);
            }

            return;
        }

        $this->pollOnly($operation, $renderer, $files, $revisions);
    }

    public function resumeFromJavaResult(
        PdfRendererClient $renderer,
        PdfImmutableFileStore $files,
        PdfRevisionService $revisions,
    ): void {
        $operation = $this->activateOrLoad();
        if ($this->isTerminal($operation->state)) {
            return;
        }
        if ($operation->stage !== 'java_polling'
            || $operation->java_execution_state !== 'completed') {
            throw new RuntimeException('Manual-review result resume is not fenced to a completed Java execution.');
        }
        $execution = PdfJavaSigningExecution::query()
            ->where('operation_uuid', $operation->operation_uuid)
            ->firstOrFail();
        if ($execution->state !== 'completed'
            || $execution->result_integrity_state !== 'available'
            || $execution->result_sha256 === null
            || $execution->result_size === null) {
            throw new RuntimeException('Manual-review result resume lost its completed execution evidence.');
        }
        $this->complete($operation, [
            'state' => 'completed',
            'resultSha256' => $execution->result_sha256,
            'resultSize' => (int) $execution->result_size,
        ], $renderer, $files, $revisions);
    }

    public function failed(Throwable $exception): void
    {
        $operation = PdfSigningOperation::query()
            ->where('operation_uuid', $this->operationUuid)
            ->first();
        if ($operation === null || $this->isTerminal($operation->state)) {
            return;
        }
        $this->finishFailure(
            $operation,
            'manual_review',
            'LARAVEL_OPERATION_RETRY_EXHAUSTED',
            true,
        );
    }

    private function submitOnce(
        PdfSigningOperation $operation,
        PdfRendererClient $renderer,
        PdfImmutableFileStore $files,
        PdfRevisionService $revisions,
    ): void {
        $source = PdfFile::query()->findOrFail($operation->expected_source_revision_id);
        $appearance = PdfSignatureAppearanceArtifact::query()
            ->where('claimed_by_operation_id', $operation->id)
            ->firstOrFail();
        $sourcePath = $files->verifiedAbsolutePath(
            $source->file_path,
            $operation->expected_source_sha256,
            (int) $source->file_size,
        );
        $appearancePath = $files->verifiedAbsolutePathByHash(
            $appearance->file_path,
            $operation->appearance_sha256,
        );

        try {
            $response = $renderer->submitSigningExecution(
                $sourcePath,
                $appearancePath,
                $this->javaOperation($operation),
                $this->command($operation),
            );
        } catch (Throwable $exception) {
            $this->enterPolling($operation, 'JAVA_POST_OUTCOME_UNCERTAIN');

            return;
        }

        $this->projectJavaState($operation, $response, $renderer, $files, $revisions);
    }

    private function pollOnly(
        PdfSigningOperation $operation,
        PdfRendererClient $renderer,
        PdfImmutableFileStore $files,
        PdfRevisionService $revisions,
    ): void {
        try {
            $response = $renderer->signingExecutionStatus(
                $operation->operation_uuid,
                $this->javaOperation($operation),
            );
            $this->projectJavaState($operation, $response, $renderer, $files, $revisions);
        } catch (PdfRendererHttpException $exception) {
            if ($exception->statusCode === 404
                && $operation->java_execution_registration_deadline_at !== null
                && now()->lt($operation->java_execution_registration_deadline_at)) {
                $this->schedulePoll($operation, 'JAVA_EXECUTION_NOT_REGISTERED_YET');

                return;
            }

            if ($exception->statusCode === 404) {
                $this->finishFailure($operation, 'failed', 'JAVA_EXECUTION_REGISTRATION_DEADLINE', false);

                return;
            }

            $this->schedulePoll($operation, 'JAVA_STATUS_TEMPORARILY_UNAVAILABLE');
        } catch (Throwable $exception) {
            $this->schedulePoll($operation, 'JAVA_STATUS_TRANSPORT_ERROR');
        }
    }

    /** @param array<string, mixed> $response */
    private function projectJavaState(
        PdfSigningOperation $operation,
        array $response,
        PdfRendererClient $renderer,
        PdfImmutableFileStore $files,
        PdfRevisionService $revisions,
    ): void {
        $state = (string) ($response['state'] ?? '');

        match ($state) {
            'completed' => $this->complete($operation, $response, $renderer, $files, $revisions),
            'failed_before_private_key' => $this->handlePreKeyFailure($operation, $response),
            'failed_after_private_key_known' => $this->finishFailure(
                $operation,
                'irreversible_failed',
                (string) ($response['errorCode'] ?? 'JAVA_FAILED_AFTER_PRIVATE_KEY'),
                true,
            ),
            'outcome_unknown' => $this->finishFailure(
                $operation,
                'manual_review',
                (string) ($response['errorCode'] ?? 'JAVA_OUTCOME_UNKNOWN'),
                true,
            ),
            'claimed', 'executing' => $this->schedulePoll($operation, null, $response),
            default => throw new RuntimeException('Java execution returned an unsupported state.'),
        };
    }

    /** @param array<string, mixed> $response */
    private function handlePreKeyFailure(PdfSigningOperation $operation, array $response): void
    {
        if (($response['retryability'] ?? null) === 'same_operation'
            && isset($response['nextRetryAt'])
            && (int) ($response['attemptCount'] ?? 0) < (int) ($response['maximumAttempts'] ?? 0)) {
            $this->scheduleExplicitPreKeyRetry($operation, $response);

            return;
        }

        $this->finishFailure(
            $operation,
            'failed',
            (string) ($response['errorCode'] ?? 'JAVA_FAILED_BEFORE_PRIVATE_KEY'),
            false,
        );
    }

    /** @param array<string, mixed> $response */
    private function scheduleExplicitPreKeyRetry(PdfSigningOperation $operation, array $response): void
    {
        $nextRetryAt = Carbon::parse((string) $response['nextRetryAt']);
        DB::transaction(function () use ($operation, $response, $nextRetryAt): void {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $execution = PdfJavaSigningExecution::query()
                ->where('operation_uuid', $locked->operation_uuid)
                ->lockForUpdate()
                ->firstOrFail();
            $policy = PdfSigningPolicyVersion::query()->findOrFail($locked->signing_policy_version_id);

            if ($locked->state !== 'processing'
                || ! in_array($locked->stage, ['java_call', 'java_polling'], true)
                || $execution->state !== 'failed_before_private_key'
                || $execution->retryability !== 'same_operation'
                || $execution->private_key_started_at !== null
                || (int) $execution->attempt_count >= (int) $execution->max_attempts
                || $execution->input_fingerprint !== $locked->input_fingerprint
                || $execution->policy_hash !== $locked->policy_hash) {
                throw new RuntimeException('Pre-key retry snapshot is no longer eligible.');
            }
            $now = now();
            $locked->update([
                'stage' => 'java_call',
                'lease_owner' => (string) Str::uuid(),
                'lease_epoch' => $locked->lease_epoch + 1,
                'lease_expires_at' => $now->copy()->addSeconds(
                    $policy->java_execution_registration_timeout_seconds
                    + $policy->java_execution_timeout_seconds + 60,
                ),
                'heartbeat_at' => $now,
                'java_request_started_at' => null,
                'java_execution_registration_deadline_at' => null,
                'java_execution_state' => 'failed_before_private_key',
                'next_java_poll_at' => $nextRetryAt,
                'error_code' => $response['errorCode'] ?? null,
                'error_retryability' => 'same_operation_pre_key',
            ]);
        }, 3);
        self::dispatch($operation->operation_uuid)->delay($nextRetryAt);
    }

    /** @param array<string, mixed> $response */
    private function complete(
        PdfSigningOperation $operation,
        array $response,
        PdfRendererClient $renderer,
        PdfImmutableFileStore $files,
        PdfRevisionService $revisions,
    ): void {
        $promotedReplay = $operation->state === 'promoted';
        $candidateFallback = null;
        if ($promotedReplay) {
            try {
                $result = $this->readPromotedResult($operation, $files);
            } catch (Throwable $promotedException) {
                $this->finishFailure($operation, 'manual_review', 'PROMOTED_FINAL_INTEGRITY_FAILURE', true);

                return;
            }
        } else {
            try {
                $result = $renderer->signingExecutionResult(
                    $operation->operation_uuid,
                    $this->javaOperation($operation),
                );
            } catch (Throwable $exception) {
                try {
                    $candidateFallback = $this->readCandidateFallback($operation, $response, $files);
                    $result = $candidateFallback;
                } catch (Throwable $fallbackException) {
                    $this->finishFailure($operation, 'manual_review', 'JAVA_COMPLETED_RESULT_UNAVAILABLE', true);

                    return;
                }
            }
            if ($candidateFallback === null) {
                try {
                    $candidateFallback = $this->readCandidateFallback($operation, $result, $files);
                } catch (RuntimeException $fallbackException) {
                    if (! str_contains($fallbackException->getMessage(), 'fallback is missing')) {
                        $this->finishFailure($operation, 'manual_review', 'DOWNSTREAM_CANDIDATE_AMBIGUOUS', true);

                        return;
                    }
                }
            }
        }
        $recoveredFromDownstream = $promotedReplay || $candidateFallback !== null;
        if (($response['resultSha256'] ?? null) !== $result['sha256']
            || (int) ($response['resultSize'] ?? -1) !== $result['size']) {
            $this->finishFailure($operation, 'manual_review', 'JAVA_RESULT_RESPONSE_MISMATCH', true);

            return;
        }
        try {
            $inspection = $renderer->inspectSignatureBytes($result['body']);
            $verification = $renderer->verifySignatureBytes($result['body']);
            $request = PdfSigningRequest::query()->with('act')->findOrFail($operation->request_id);
            $this->assertGeneratedRevision($operation, $request, $inspection, $verification);
        } catch (Throwable $exception) {
            $this->finishFailure($operation, 'manual_review', 'GENERATED_REVISION_VERIFICATION_FAILED', true);

            return;
        }

        $fenced = $this->claimCompletionFence($operation, $result, $recoveredFromDownstream);
        if ($fenced->state === 'completed') {
            return;
        }
        $stagingPath = $this->operationStagingPath($fenced);
        $finalPath = $this->operationFinalPath($fenced);
        if ($candidateFallback !== null) {
            $files->adoptOperationCandidate(
                $candidateFallback['path'],
                $stagingPath,
                $finalPath,
                $result['sha256'],
                $result['size'],
            );
        }
        $files->ensureOperationCandidate(
            $result['body'],
            $stagingPath,
            $finalPath,
            $result['sha256'],
            $result['size'],
        );
        if ($fenced->state === 'processing') {
            $fenced = $this->advanceCompletionStage($fenced, 'verifying', $recoveredFromDownstream);
            $fenced = $this->advanceCompletionStage($fenced, 'promoting', $recoveredFromDownstream);
        }
        $promoted = $files->promoteOperationCandidate(
            $stagingPath,
            $finalPath,
            $result['sha256'],
            $result['size'],
        );
        $fenced = $this->recordPromotedResult($fenced, $promoted, $recoveredFromDownstream);

        try {
            DB::transaction(function () use (
                $fenced,
                $result,
                $inspection,
                $verification,
                $revisions,
                $promoted,
                $recoveredFromDownstream,
                $candidateFallback,
            ): void {
                $lockedOperation = PdfSigningOperation::query()->lockForUpdate()->findOrFail($fenced->id);
                $execution = PdfJavaSigningExecution::query()
                    ->where('operation_uuid', $lockedOperation->operation_uuid)
                    ->lockForUpdate()
                    ->firstOrFail();
                $document = PdfDocument::query()->lockForUpdate()->findOrFail($lockedOperation->document_id);
                $workflow = PdfSigningWorkflow::query()->lockForUpdate()->findOrFail($lockedOperation->workflow_id);
                $request = PdfSigningRequest::query()->lockForUpdate()->findOrFail($lockedOperation->request_id);
                $source = PdfFile::query()->lockForUpdate()->findOrFail($lockedOperation->expected_source_revision_id);
                $appearance = PdfSignatureAppearanceArtifact::query()
                    ->where('claimed_by_operation_id', $lockedOperation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedOperation->state !== 'promoted'
                    || $lockedOperation->stage !== 'committing'
                    || $lockedOperation->lease_owner !== $fenced->lease_owner
                    || (int) $lockedOperation->lease_epoch !== (int) $fenced->lease_epoch
                    || $lockedOperation->lease_expires_at === null
                    || $lockedOperation->lease_expires_at->lte(now())
                    || (int) $lockedOperation->document_evidence_hold_mask !== 0
                    || $execution->state !== 'completed'
                    || ! $this->completionExecutionResultIsEligible(
                        $lockedOperation,
                        $execution,
                        $recoveredFromDownstream,
                    )
                    || ! $this->completionExecutionHoldIsEligible($lockedOperation, $execution)
                    || ($execution->legal_hold_until !== null && $execution->legal_hold_until->gt(now()))
                    || $execution->result_sha256 !== $result['sha256']
                    || (int) $execution->result_size !== $result['size']
                    || $lockedOperation->promoted_file_path !== $promoted['path']
                    || $lockedOperation->result_sha256 !== $promoted['sha256']
                    || (int) $lockedOperation->result_size !== $promoted['size']
                    || $document->active_operation_id !== $lockedOperation->id
                    || $workflow->active_operation_id !== $lockedOperation->id
                    || $workflow->current_revision_id !== $lockedOperation->expected_source_revision_id
                    || $request->status !== 'signing'
                    || $request->expected_source_revision_id !== $lockedOperation->expected_source_revision_id
                    || $source->sha256_hash !== $lockedOperation->expected_source_sha256
                    || $appearance->state !== 'claimed') {
                    throw new RuntimeException('PDF operation completion snapshot changed before transaction B.');
                }

                $actor = User::query()->findOrFail($lockedOperation->actor_user_id);
                $this->assertLeaseFence($lockedOperation, $fenced);
                if ($recoveredFromDownstream
                    && in_array($execution->result_integrity_state, ['missing', 'breached'], true)) {
                    $this->appendDownstreamRecoveryEvent(
                        $lockedOperation,
                        $execution,
                        $candidateFallback['path'] ?? $lockedOperation->promoted_file_path,
                    );
                }
                $revision = $revisions->registerOperationRevision(
                    $document,
                    $source,
                    $lockedOperation->result_revision_uuid,
                    'handwritten_signature',
                    $promoted['path'],
                    $promoted['sha256'],
                    $promoted['size'],
                    $actor,
                    [
                        'version' => 'pdf-signing-result-v1',
                        'operation_uuid' => $lockedOperation->operation_uuid,
                        'input_fingerprint' => $lockedOperation->input_fingerprint,
                        'policy_hash' => $lockedOperation->policy_hash,
                        'java_validation_report_hash' => $execution->validation_report_hash,
                        'inspection' => $inspection,
                        'verification' => $verification,
                    ],
                );
                $field = PdfSigningField::query()
                    ->where('request_id', $request->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $act = PdfSigningAct::query()->lockForUpdate()->findOrFail($request->signing_act_id);

                $request->update(['status' => 'signed', 'completed_revision_id' => $revision->id]);
                $field->update(['status' => 'signed']);
                PdfSigningSlot::query()->where('field_id', $field->id)->update(['status' => 'rendered']);
                $act->update(['status' => 'completed', 'completed_revision_id' => $revision->id]);
                $appearance->update([
                    'state' => 'consumed',
                    'evidence_hold_mask' => $appearance->evidence_hold_mask
                        & ~(self::MANUAL_REVIEW_HOLD | self::QUARANTINE_HOLD),
                    'evidence_hold_state' => ($appearance->evidence_hold_mask
                        & ~(self::MANUAL_REVIEW_HOLD | self::QUARANTINE_HOLD)) === 0 ? 'none' : 'active',
                    'hold_released_at' => ($appearance->evidence_hold_mask
                        & ~(self::MANUAL_REVIEW_HOLD | self::QUARANTINE_HOLD)) === 0 ? now() : null,
                    'retention_until' => now()->addDay(),
                    'lock_version' => $appearance->lock_version + 1,
                ]);
                $remainingExecutionHold = $execution->evidence_hold_mask
                    & ~(self::MANUAL_REVIEW_HOLD | self::QUARANTINE_HOLD);
                $this->updateExecutionEvidenceHold(
                    $execution,
                    $remainingExecutionHold,
                    'EVIDENCE_HOLD_RELEASED_AFTER_ADOPTION',
                );
                $workflow->update(['current_revision_id' => $revision->id, 'active_operation_id' => null]);
                $remainingDocumentHold = $document->integrity_hold_mask & ~self::DOCUMENT_MANUAL_REVIEW_HOLD;
                $document->update([
                    'active_operation_id' => null,
                    'integrity_hold_mask' => $remainingDocumentHold,
                    'integrity_state' => $remainingDocumentHold === 0 ? 'ok' : 'hold',
                    'integrity_hold_released_at' => $remainingDocumentHold === 0 ? now() : null,
                    'integrity_version' => $document->integrity_version
                        + ($remainingDocumentHold !== $document->integrity_hold_mask ? 1 : 0),
                ]);

                $next = PdfSigningRequest::query()
                    ->where('workflow_id', $workflow->id)
                    ->where('sequence', $request->sequence + 1)
                    ->lockForUpdate()
                    ->first();
                if ($next !== null) {
                    $next->update([
                        'status' => 'available',
                        'expected_source_revision_id' => $revision->id,
                        'expected_source_sha256' => $revision->sha256_hash,
                    ]);
                    $workflow->update(['status' => 'ready']);
                    // The turn has moved on; tell whoever it moved to.
                    app(PdfSigningNotifier::class)->notifyAvailable($next);
                } else {
                    if ($document->published_revision_id !== $workflow->publication_base_revision_id
                        || (int) $document->publication_version !== (int) $workflow->expected_publication_version) {
                        throw new RuntimeException('PDF publication pointer CAS failed.');
                    }
                    $previousPublishedRevisionId = $document->published_revision_id;
                    if ($previousPublishedRevisionId !== null) {
                        PdfFile::query()->whereKey($previousPublishedRevisionId)->update(['disposition' => 'superseded']);
                    }
                    $revision->update(['disposition' => 'published', 'first_published_at' => now()]);
                    $workflow->update(['status' => 'completed']);
                    $document->update([
                        'active_workflow_id' => null,
                        'published_revision_id' => $revision->id,
                        'publication_version' => $document->publication_version + 1,
                        'status' => 'published',
                    ]);
                    DB::table('pdf_document_publication_events')->insert([
                        'event_uuid' => (string) Str::uuid(),
                        'document_id' => $document->id,
                        'revision_id' => $revision->id,
                        'event_type' => 'published',
                        'reason_code' => 'ISSUER_SIGNATURE_COMPLETED',
                        'actor_user_id' => $actor->id,
                        'occurred_at' => now(),
                        'audit_context_hash' => $lockedOperation->audit_context_hash,
                        'previous_published_revision_id' => $previousPublishedRevisionId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $minimumExecutionRetention = now()->addDays(7);
                if ($execution->retention_until === null || $execution->retention_until->lt($minimumExecutionRetention)) {
                    $execution->update(['retention_until' => $minimumExecutionRetention]);
                }

                $lockedOperation->update([
                    'state' => 'completed',
                    'stage' => 'done',
                    'java_execution_state' => 'completed',
                    'result_revision_id' => $revision->id,
                    'promoted_file_path' => $revision->file_path,
                    'result_sha256' => $revision->sha256_hash,
                    'result_size' => $revision->file_size,
                    'error_code' => null,
                    'error_retryability' => null,
                    'response_fingerprint' => hash('sha256', CanonicalJson::encode([
                        'operation_uuid' => $lockedOperation->operation_uuid,
                        'result_revision_uuid' => $revision->revision_uuid,
                        'result_sha256' => $revision->sha256_hash,
                    ])),
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                ]);
            }, 3);
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    /** @param array{sha256: string, size: int} $result */
    private function claimCompletionFence(
        PdfSigningOperation $operation,
        array $result,
        bool $recoveredFromDownstream,
    ): PdfSigningOperation {
        return DB::transaction(function () use ($operation, $result, $recoveredFromDownstream): PdfSigningOperation {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->state === 'completed') {
                return $locked;
            }
            $execution = PdfJavaSigningExecution::query()
                ->where('operation_uuid', $locked->operation_uuid)
                ->lockForUpdate()
                ->firstOrFail();
            $processing = $locked->state === 'processing'
                && in_array($locked->stage, ['java_call', 'java_polling', 'staging', 'verifying', 'promoting'], true);
            $promoted = $locked->state === 'promoted'
                && $locked->stage === 'committing'
                && $locked->promoted_file_path === $this->operationFinalPath($locked)
                && $locked->result_sha256 === $result['sha256']
                && (int) $locked->result_size === $result['size'];
            if ((! $processing && ! $promoted)
                || $locked->lease_owner === null
                || $locked->lease_expires_at === null
                || $locked->lease_expires_at->lte(now())
                || $locked->lease_owner !== $operation->lease_owner
                || (int) $locked->lease_epoch !== (int) $operation->lease_epoch
                || (int) $locked->document_evidence_hold_mask !== 0
                || $locked->result_revision_uuid === null
                || $execution->state !== 'completed'
                || ! $this->completionExecutionResultIsEligible(
                    $locked,
                    $execution,
                    $recoveredFromDownstream,
                )
                || ! $this->completionExecutionHoldIsEligible($locked, $execution)
                || ($execution->legal_hold_until !== null && $execution->legal_hold_until->gt(now()))
                || ! hash_equals((string) $execution->result_sha256, $result['sha256'])
                || (int) $execution->result_size !== $result['size']) {
                throw new RuntimeException('PDF completion worker lost its lease or result fence.');
            }
            if ($processing && in_array($locked->stage, ['java_call', 'java_polling'], true)) {
                $locked->update([
                    'stage' => 'staging',
                    'java_execution_state' => 'completed',
                    'heartbeat_at' => now(),
                ]);
            }

            return $locked->refresh();
        }, 3);
    }

    private function advanceCompletionStage(
        PdfSigningOperation $operation,
        string $targetStage,
        bool $recoveredFromDownstream,
    ): PdfSigningOperation {
        $order = ['staging' => 0, 'verifying' => 1, 'promoting' => 2];
        if (! array_key_exists($targetStage, $order)) {
            throw new RuntimeException('Unsupported PDF completion stage transition.');
        }

        return DB::transaction(function () use (
            $operation,
            $targetStage,
            $order,
            $recoveredFromDownstream,
        ): PdfSigningOperation {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $execution = PdfJavaSigningExecution::query()
                ->where('operation_uuid', $locked->operation_uuid)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertLeaseFence($locked, $operation);
            if ($locked->state !== 'processing'
                || ! array_key_exists($locked->stage, $order)
                || (int) $locked->document_evidence_hold_mask !== 0
                || $execution->state !== 'completed'
                || ! $this->completionExecutionResultIsEligible(
                    $locked,
                    $execution,
                    $recoveredFromDownstream,
                )
                || ! $this->completionExecutionHoldIsEligible($locked, $execution)) {
                throw new RuntimeException('PDF completion stage fence is no longer eligible.');
            }
            if ($order[$locked->stage] < $order[$targetStage]) {
                $locked->update(['stage' => $targetStage, 'heartbeat_at' => now()]);
            }

            return $locked->refresh();
        }, 3);
    }

    /**
     * @param  array{location: 'final', path: string, absolute_path: string, sha256: string, size: int}  $promoted
     */
    private function recordPromotedResult(
        PdfSigningOperation $operation,
        array $promoted,
        bool $recoveredFromDownstream,
    ): PdfSigningOperation {
        return DB::transaction(function () use (
            $operation,
            $promoted,
            $recoveredFromDownstream,
        ): PdfSigningOperation {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            $execution = PdfJavaSigningExecution::query()
                ->where('operation_uuid', $locked->operation_uuid)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertLeaseFence($locked, $operation);
            if ($locked->state === 'promoted' && $locked->stage === 'committing') {
                if ($locked->promoted_file_path !== $promoted['path']
                    || $locked->result_sha256 !== $promoted['sha256']
                    || (int) $locked->result_size !== $promoted['size']) {
                    throw new RuntimeException('Promoted PDF operation ledger conflicts with final bytes.');
                }

                return $locked;
            }
            if ($locked->state !== 'processing' || $locked->stage !== 'promoting'
                || (int) $locked->document_evidence_hold_mask !== 0
                || $execution->state !== 'completed'
                || ! $this->completionExecutionResultIsEligible(
                    $locked,
                    $execution,
                    $recoveredFromDownstream,
                )
                || ! $this->completionExecutionHoldIsEligible($locked, $execution)
                || ! hash_equals((string) $execution->result_sha256, $promoted['sha256'])
                || (int) $execution->result_size !== $promoted['size']) {
                throw new RuntimeException('Promoted PDF operation lost its exact completion fence.');
            }
            $locked->update([
                'state' => 'promoted',
                'stage' => 'committing',
                'promoted_file_path' => $promoted['path'],
                'result_sha256' => $promoted['sha256'],
                'result_size' => $promoted['size'],
                'heartbeat_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    private function assertLeaseFence(
        PdfSigningOperation $locked,
        PdfSigningOperation $expected,
    ): void {
        if ($locked->lease_owner === null
            || $locked->lease_owner !== $expected->lease_owner
            || (int) $locked->lease_epoch !== (int) $expected->lease_epoch
            || $locked->lease_expires_at === null
            || $locked->lease_expires_at->lte(now())) {
            throw new RuntimeException('PDF operation lease fence was lost.');
        }
    }

    private function completionExecutionHoldIsEligible(
        PdfSigningOperation $operation,
        PdfJavaSigningExecution $execution,
    ): bool {
        $expectedMask = $operation->error_retryability === 'manual_adoption_result_only'
            ? self::MANUAL_REVIEW_HOLD | self::QUARANTINE_HOLD
            : 0;

        return (int) $execution->evidence_hold_mask === $expectedMask
            && $execution->evidence_hold_state === ($expectedMask === 0 ? 'none' : 'active')
            && ($execution->legal_hold_until === null || $execution->legal_hold_until->lte(now()));
    }

    private function completionExecutionResultIsEligible(
        PdfSigningOperation $operation,
        PdfJavaSigningExecution $execution,
        bool $recoveredFromDownstream,
    ): bool {
        if ($execution->result_integrity_state === 'available') {
            return true;
        }

        return $recoveredFromDownstream
            && in_array($operation->state, ['processing', 'promoted'], true)
            && in_array($execution->result_integrity_state, ['missing', 'breached'], true)
            && ($operation->state !== 'promoted'
                || ($operation->stage === 'committing'
                    && $operation->promoted_file_path !== null
                    && $operation->result_sha256 !== null
                    && $operation->result_sha256 === $execution->result_sha256
                    && (int) $operation->result_size === (int) $execution->result_size));
    }

    /** @param array<string, mixed> $identity */
    private function readCandidateFallback(
        PdfSigningOperation $operation,
        array $identity,
        PdfImmutableFileStore $files,
    ): array {
        $sha256 = $identity['sha256'] ?? $identity['resultSha256'] ?? null;
        $size = $identity['size'] ?? $identity['resultSize'] ?? null;
        if ($operation->result_revision_uuid === null
            || ! is_string($sha256)
            || ! is_numeric($size)) {
            throw new RuntimeException('Operation candidate fallback has no result identity.');
        }
        $policy = PdfSigningPolicyVersion::query()->findOrFail($operation->signing_policy_version_id);

        return $files->readOperationCandidateFallback(
            $operation->operation_uuid,
            $operation->result_revision_uuid,
            $sha256,
            (int) $size,
            (int) $policy->generated_revision_max_bytes,
        );
    }

    /** @return array{body: string, sha256: string, size: int} */
    private function readPromotedResult(
        PdfSigningOperation $operation,
        PdfImmutableFileStore $files,
    ): array {
        if ($operation->state !== 'promoted'
            || $operation->stage !== 'committing'
            || $operation->promoted_file_path === null
            || $operation->result_sha256 === null
            || $operation->result_size === null
            || $operation->promoted_file_path !== $this->operationFinalPath($operation)) {
            throw new RuntimeException('Operation has no trusted promoted result fallback.');
        }
        $policy = PdfSigningPolicyVersion::query()->findOrFail($operation->signing_policy_version_id);
        if ((int) $operation->result_size > (int) $policy->generated_revision_max_bytes) {
            throw new RuntimeException('Promoted result exceeds the frozen generated revision budget.');
        }
        $body = $files->readVerifiedImmutableFile(
            $operation->promoted_file_path,
            $operation->result_sha256,
            (int) $operation->result_size,
            (int) $policy->generated_revision_max_bytes,
        );

        return [
            'body' => $body,
            'sha256' => $operation->result_sha256,
            'size' => (int) $operation->result_size,
        ];
    }

    private function appendDownstreamRecoveryEvent(
        PdfSigningOperation $operation,
        PdfJavaSigningExecution $execution,
        ?string $recoverySourcePath,
    ): void {
        $eventType = 'DOWNSTREAM_COPY_RECOVERED_AFTER_JAVA_RESULT_INTEGRITY_FAILURE';
        if (DB::table('pdf_signing_operation_events')
            ->where('operation_id', $operation->id)
            ->where('event_type', $eventType)
            ->exists()) {
            return;
        }
        $previousHash = DB::table('pdf_signing_operation_events')
            ->where('operation_id', $operation->id)
            ->orderByDesc('id')
            ->value('event_hash');
        $occurredAt = now();
        $payload = [
            'execution_result_integrity_state' => $execution->result_integrity_state,
            'recovery_source_path' => $recoverySourcePath,
            'promoted_file_path' => $operation->promoted_file_path,
            'result_sha256' => $operation->result_sha256,
            'result_size' => (int) $operation->result_size,
        ];
        $event = [
            'operation_uuid' => $operation->operation_uuid,
            'event_type' => $eventType,
            'event_payload' => $payload,
            'previous_event_hash' => $previousHash,
            'occurred_at' => $occurredAt->toISOString(),
        ];
        DB::table('pdf_signing_operation_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'operation_id' => $operation->id,
            'event_type' => $eventType,
            'actor_user_id' => null,
            'reason_code' => 'JAVA_RESULT_INTEGRITY_FALLBACK',
            'resolution_fingerprint' => null,
            'event_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_event_hash' => $previousHash,
            'event_hash' => hash('sha256', CanonicalJson::encode($event)),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    private function operationStagingPath(PdfSigningOperation $operation): string
    {
        return "workflow/staging/{$operation->operation_uuid}/{$operation->lease_epoch}/candidate.pdf";
    }

    private function operationFinalPath(PdfSigningOperation $operation): string
    {
        if ($operation->promoted_file_path !== null) {
            $pattern = '#^workflow/revisions/'
                .preg_quote((string) $operation->result_revision_uuid, '#').'/'
                .preg_quote($operation->operation_uuid, '#').'/[0-9]+/document\.pdf$#';
            if (preg_match($pattern, $operation->promoted_file_path) !== 1) {
                throw new RuntimeException('Promoted PDF path escaped its operation identity contract.');
            }

            return $operation->promoted_file_path;
        }

        return "workflow/revisions/{$operation->result_revision_uuid}/{$operation->operation_uuid}/{$operation->lease_epoch}/document.pdf";
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @param  array<string, mixed>  $verification
     */
    private function assertGeneratedRevision(
        PdfSigningOperation $operation,
        PdfSigningRequest $request,
        array $inspection,
        array $verification,
    ): void {
        if (($verification['documentCurrentState'] ?? null) !== 'valid'
            || (int) ($verification['docMdpPermission'] ?? 0) !== 2
            || count($verification['signatures'] ?? []) !== (int) $request->sequence
            || (int) ($inspection['signatureCount'] ?? -1) !== (int) $request->sequence) {
            throw new RuntimeException('Generated revision failed sequential PAdES verification.');
        }
        $field = collect($inspection['fields'] ?? [])->firstWhere('fieldName', $operation->target_field_name);
        if (! is_array($field) || ($field['signed'] ?? false) !== true) {
            throw new RuntimeException('Generated revision did not fill the frozen signature field.');
        }
    }

    private function activateOrLoad(): PdfSigningOperation
    {
        return DB::transaction(function (): PdfSigningOperation {
            $operation = PdfSigningOperation::query()
                ->where('operation_uuid', $this->operationUuid)
                ->lockForUpdate()
                ->firstOrFail();
            if ($operation->state === 'claimed' && $operation->stage === 'awaiting_dispatch') {
                $policy = PdfSigningPolicyVersion::query()->findOrFail($operation->signing_policy_version_id);
                $now = now();
                $operation->update([
                    'state' => 'processing',
                    'stage' => 'java_call',
                    'lease_owner' => (string) Str::uuid(),
                    'lease_epoch' => $operation->lease_epoch + 1,
                    'lease_expires_at' => $now->copy()->addSeconds(
                        $policy->java_execution_registration_timeout_seconds
                        + $policy->java_execution_timeout_seconds + 60,
                    ),
                    'heartbeat_at' => $now,
                ]);
            } elseif ($operation->state === 'processing'
                && $operation->stage === 'java_polling'
                && $operation->java_execution_state === 'completed'
                && $operation->lease_owner === null) {
                $policy = PdfSigningPolicyVersion::query()->findOrFail($operation->signing_policy_version_id);
                $now = now();
                $operation->update([
                    'lease_owner' => (string) Str::uuid(),
                    'lease_epoch' => $operation->lease_epoch + 1,
                    'lease_expires_at' => $now->copy()->addSeconds(
                        $policy->java_execution_timeout_seconds + 60,
                    ),
                    'heartbeat_at' => $now,
                ]);
            }

            return $operation->refresh();
        }, 3);
    }

    /** @return array{operation: PdfSigningOperation, should_submit: bool} */
    private function markRequestStarted(PdfSigningOperation $operation): array
    {
        return DB::transaction(function () use ($operation): array {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->state !== 'processing'
                || $locked->stage !== 'java_call'
                || $locked->java_request_started_at !== null
                || $locked->lease_owner === null
                || $locked->lease_owner !== $operation->lease_owner
                || (int) $locked->lease_epoch !== (int) $operation->lease_epoch
                || $locked->lease_expires_at === null
                || $locked->lease_expires_at->lte(now())) {
                return ['operation' => $locked->refresh(), 'should_submit' => false];
            }
            $policy = PdfSigningPolicyVersion::query()->findOrFail($locked->signing_policy_version_id);
            $now = now();
            $locked->update([
                'java_request_started_at' => $now,
                'java_execution_registration_deadline_at' => $now->copy()
                    ->addSeconds($policy->java_execution_registration_timeout_seconds),
            ]);

            return ['operation' => $locked->refresh(), 'should_submit' => true];
        }, 3);
    }

    /** @param array<string, mixed>|null $response */
    private function schedulePoll(
        PdfSigningOperation $operation,
        ?string $errorCode,
        ?array $response = null,
    ): void {
        $delay = 2;
        DB::transaction(function () use ($operation, $errorCode, $response, $delay): void {
            $locked = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($this->isTerminal($locked->state)) {
                return;
            }
            $locked->update([
                'stage' => 'java_polling',
                'java_execution_state' => $response['state'] ?? $locked->java_execution_state,
                'java_execution_deadline_at' => isset($response['executionDeadlineAt'])
                    ? $response['executionDeadlineAt']
                    : $locked->java_execution_deadline_at,
                'next_java_poll_at' => now()->addSeconds($delay),
                'java_poll_count' => $locked->java_poll_count + 1,
                'heartbeat_at' => now(),
                'error_code' => $errorCode,
            ]);
        }, 3);
        self::dispatch($operation->operation_uuid)->delay(now()->addSeconds($delay));
    }

    private function enterPolling(PdfSigningOperation $operation, string $errorCode): void
    {
        $this->schedulePoll($operation, $errorCode);
    }

    private function finishFailure(
        PdfSigningOperation $operation,
        string $terminalState,
        string $errorCode,
        bool $evidenceHold,
    ): void {
        DB::transaction(function () use ($operation, $terminalState, $errorCode, $evidenceHold): void {
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

            if ($this->isTerminal($locked->state)) {
                return;
            }
            $javaState = $execution?->state ?? match ($terminalState) {
                'failed' => 'failed_before_private_key',
                'irreversible_failed' => 'failed_after_private_key_known',
                default => 'outcome_unknown',
            };
            if ($execution !== null && $evidenceHold) {
                $holdBit = $terminalState === 'manual_review'
                    ? self::MANUAL_REVIEW_HOLD
                    : self::IRREVERSIBLE_FAILURE_HOLD;
                $this->updateExecutionEvidenceHold(
                    $execution,
                    $execution->evidence_hold_mask | $holdBit | self::QUARANTINE_HOLD,
                    'EVIDENCE_HOLD_ADDED',
                );
            }
            $locked->update([
                'state' => $terminalState,
                'stage' => 'done',
                'java_execution_state' => $javaState,
                'error_code' => $errorCode,
                'error_retryability' => $terminalState === 'failed' ? 'new_generation_only' : 'none',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
            ]);
            $workflow->update([
                'active_operation_id' => null,
                'status' => $terminalState === 'failed' ? 'ready' : $terminalState,
            ]);
            $document->update([
                'active_operation_id' => null,
                'status' => $terminalState === 'failed' ? 'signing' : $terminalState,
                'integrity_state' => $terminalState === 'manual_review' ? 'hold' : $document->integrity_state,
                'integrity_hold_mask' => $terminalState === 'manual_review'
                    ? ($document->integrity_hold_mask | self::DOCUMENT_MANUAL_REVIEW_HOLD)
                    : $document->integrity_hold_mask,
                'integrity_version' => $terminalState === 'manual_review'
                    && ($document->integrity_hold_mask & self::DOCUMENT_MANUAL_REVIEW_HOLD) === 0
                        ? $document->integrity_version + 1
                        : $document->integrity_version,
                'integrity_hold_started_at' => $terminalState === 'manual_review'
                    ? ($document->integrity_hold_started_at ?? now())
                    : $document->integrity_hold_started_at,
            ]);
            $request->update(['status' => $terminalState === 'failed' ? 'available' : $terminalState]);
            $appearanceHoldMask = $evidenceHold
                ? ($appearance->evidence_hold_mask
                    | ($terminalState === 'manual_review'
                        ? self::MANUAL_REVIEW_HOLD
                        : self::IRREVERSIBLE_FAILURE_HOLD)
                    | self::QUARANTINE_HOLD)
                : $appearance->evidence_hold_mask;
            $appearance->update([
                'state' => 'quarantined',
                'evidence_hold_mask' => $appearanceHoldMask,
                'evidence_hold_state' => $appearanceHoldMask === 0 ? 'none' : 'active',
                'hold_started_at' => $evidenceHold ? ($appearance->hold_started_at ?? now()) : null,
                'retention_until' => $evidenceHold ? now()->addDays(8) : now()->addDay(),
                'lock_version' => $appearance->lock_version + 1,
            ]);
        }, 3);
    }

    private function updateExecutionEvidenceHold(
        PdfJavaSigningExecution $execution,
        int $newMask,
        string $eventType,
    ): void {
        if ($newMask === (int) $execution->evidence_hold_mask) {
            return;
        }
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
            'policyVersionUuid' => (string) ($operation->audit_context['operation_manifest']['signing_policy_version_uuid'] ?? ''),
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
    private function command(PdfSigningOperation $operation): array
    {
        $command = $operation->audit_context['command'] ?? null;
        if (! is_array($command)) {
            throw new RuntimeException('Frozen signature command is missing.');
        }

        return $command;
    }

    private function isTerminal(string $state): bool
    {
        return in_array($state, [
            'completed', 'failed', 'irreversible_failed', 'manual_review', 'cancelled',
        ], true);
    }
}

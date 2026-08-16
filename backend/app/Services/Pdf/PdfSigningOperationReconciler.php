<?php

namespace App\Services\Pdf;

use App\Models\PdfJavaSigningExecution;
use App\Models\PdfOperationOutbox;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningPolicyVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PdfSigningOperationReconciler
{
    public function sweep(int $limit = 100): int
    {
        $ids = PdfSigningOperation::query()
            ->where(function ($query): void {
                $query->where(function ($claimed): void {
                    $claimed->where('state', 'claimed')->whereNull('lease_owner');
                })->orWhere(function ($active): void {
                    $active->whereIn('state', ['processing', 'promoted'])
                        ->where(function ($lease): void {
                            $lease->whereNull('lease_owner')
                                ->orWhereNull('lease_expires_at')
                                ->orWhere('lease_expires_at', '<=', now());
                        });
                });
            })
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->pluck('id');
        $changed = 0;

        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id): int {
                $operation = PdfSigningOperation::query()->lockForUpdate()->findOrFail($id);
                if ($operation->state === 'claimed') {
                    if ($operation->lease_owner !== null || $operation->stage !== 'awaiting_dispatch') {
                        return 0;
                    }
                    $this->lockScope($operation);
                    $outbox = PdfOperationOutbox::query()
                        ->where('operation_id', $operation->id)
                        ->lockForUpdate()
                        ->first();
                    if ($outbox === null || $outbox->state !== 'pending') {
                        return 0;
                    }
                    $this->refreshPendingOutbox($operation, $outbox, $this->jobTypeFor($operation));
                    $this->appendEvent($operation, 'RECONCILER_REDISPATCHED_CLAIMED', [
                        'lease_epoch' => (int) $operation->lease_epoch,
                        'stage' => $operation->stage,
                    ]);

                    return 1;
                }
                if (! in_array($operation->state, ['processing', 'promoted'], true)
                    || ($operation->lease_owner !== null && $operation->lease_expires_at !== null
                        && $operation->lease_expires_at->gt(now()))
                    || (int) $operation->document_evidence_hold_mask !== 0) {
                    return 0;
                }
                if (in_array($operation->action, ['unsigned_finalize', 'prepare_fields'], true)) {
                    $this->lockScope($operation);
                    $outbox = PdfOperationOutbox::query()
                        ->where('operation_id', $operation->id)
                        ->lockForUpdate()
                        ->first();
                    if ($outbox?->state === 'cancelled') {
                        return 0;
                    }
                    $oldEpoch = (int) $operation->lease_epoch;
                    $oldState = $operation->state;
                    $operation->update([
                        'state' => $oldState === 'processing' ? 'claimed' : 'promoted',
                        'stage' => $oldState === 'processing' ? 'awaiting_dispatch' : 'committing',
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'heartbeat_at' => now(),
                    ]);
                    $this->queueOutbox($operation, 'execute_pdf_workflow_control_operation', $outbox);
                    $this->appendEvent($operation, 'RECONCILER_CONTROL_OPERATION_RECOVERY', [
                        'old_lease_epoch' => $oldEpoch,
                        'old_state' => $oldState,
                        'new_state' => $operation->state,
                        'action' => $operation->action,
                    ]);

                    return 1;
                }
                $execution = PdfJavaSigningExecution::query()
                    ->where('operation_uuid', $operation->operation_uuid)
                    ->lockForUpdate()
                    ->first();
                $this->lockScope($operation);
                $outbox = PdfOperationOutbox::query()
                    ->where('operation_id', $operation->id)
                    ->lockForUpdate()
                    ->first();
                if ($outbox?->state === 'cancelled') {
                    return 0;
                }
                $policy = PdfSigningPolicyVersion::query()->findOrFail($operation->signing_policy_version_id);
                $oldEpoch = (int) $operation->lease_epoch;
                $oldStage = $operation->stage;
                $jobType = $operation->error_retryability === 'manual_adoption_result_only'
                    ? 'resume_pdf_operation_from_java_result'
                    : 'execute_pdf_signing_operation';
                $stage = match (true) {
                    $operation->state === 'promoted' => 'committing',
                    $jobType === 'resume_pdf_operation_from_java_result' => 'java_polling',
                    $operation->java_request_started_at === null && $execution === null => 'java_call',
                    default => 'java_polling',
                };
                $now = now();
                $leaseSeconds = $policy->java_execution_timeout_seconds + 60
                    + ($stage === 'java_call' ? $policy->java_execution_registration_timeout_seconds : 0);
                $operation->update([
                    'stage' => $stage,
                    'lease_owner' => (string) Str::uuid(),
                    'lease_epoch' => $oldEpoch + 1,
                    'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                    'heartbeat_at' => $now,
                ]);
                $this->queueOutbox($operation, $jobType, $outbox);
                $this->appendEvent($operation, 'RECONCILER_LEASE_TAKEOVER', [
                    'old_lease_epoch' => $oldEpoch,
                    'new_lease_epoch' => (int) $operation->lease_epoch,
                    'old_stage' => $oldStage,
                    'new_stage' => $stage,
                    'execution_state' => $execution?->state,
                    'job_type' => $jobType,
                ]);

                return 1;
            }, 3);
        }

        return $changed;
    }

    private function lockScope(PdfSigningOperation $operation): void
    {
        DB::table('pdf_documents')->where('id', $operation->document_id)->lockForUpdate()->get();
        if ($operation->workflow_id !== null) {
            DB::table('pdf_signing_workflows')->where('id', $operation->workflow_id)->lockForUpdate()->get();
        }
        if ($operation->request_id !== null) {
            DB::table('pdf_signing_requests')->where('id', $operation->request_id)->lockForUpdate()->get();
        }
        $revisionIds = array_values(array_unique(array_filter([
            $operation->expected_source_revision_id,
            $operation->result_revision_id,
        ])));
        if ($revisionIds !== []) {
            DB::table('pdf_files')->whereIn('id', $revisionIds)->orderBy('id')->lockForUpdate()->get();
        }
        PdfSignatureAppearanceArtifact::query()
            ->where('claimed_by_operation_id', $operation->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function queueOutbox(
        PdfSigningOperation $operation,
        string $jobType,
        ?PdfOperationOutbox $lockedOutbox = null,
    ): void {
        if (! in_array($jobType, [
            'execute_pdf_signing_operation',
            'execute_pdf_workflow_control_operation',
            'resume_pdf_operation_from_java_result',
        ], true)) {
            throw new RuntimeException('Reconciler outbox job type is invalid.');
        }
        $payloadHash = hash('sha256', CanonicalJson::encode([
            'job_type' => $jobType,
            'operation_uuid' => $operation->operation_uuid,
        ]));
        $outbox = $lockedOutbox ?? PdfOperationOutbox::query()
            ->where('operation_id', $operation->id)
            ->lockForUpdate()
            ->first();
        if ($outbox === null) {
            PdfOperationOutbox::query()->create([
                'operation_id' => $operation->id,
                'job_type' => $jobType,
                'payload_hash' => $payloadHash,
                'state' => 'pending',
                'available_at' => now(),
            ]);

            return;
        }
        if ($outbox->state === 'cancelled') {
            throw new RuntimeException('Cancelled PDF operation outbox cannot be reactivated.');
        }
        $outbox->update([
            'job_type' => $jobType,
            'payload_hash' => $payloadHash,
            'state' => 'pending',
            'available_at' => now(),
            'dispatched_at' => null,
            'last_error' => null,
        ]);
    }

    private function jobTypeFor(PdfSigningOperation $operation): string
    {
        return in_array($operation->action, ['unsigned_finalize', 'prepare_fields'], true)
            ? 'execute_pdf_workflow_control_operation'
            : 'execute_pdf_signing_operation';
    }

    private function refreshPendingOutbox(
        PdfSigningOperation $operation,
        PdfOperationOutbox $outbox,
        string $jobType,
    ): void {
        $payloadHash = hash('sha256', CanonicalJson::encode([
            'job_type' => $jobType,
            'operation_uuid' => $operation->operation_uuid,
        ]));
        $outbox->update([
            'job_type' => $jobType,
            'payload_hash' => $payloadHash,
            'available_at' => now(),
            'last_error' => null,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function appendEvent(PdfSigningOperation $operation, string $eventType, array $payload): void
    {
        $previousHash = DB::table('pdf_signing_operation_events')
            ->where('operation_id', $operation->id)
            ->orderByDesc('id')
            ->value('event_hash');
        $occurredAt = now();
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
            'reason_code' => 'AUTOMATED_RECOVERY',
            'resolution_fingerprint' => null,
            'event_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_event_hash' => $previousHash,
            'event_hash' => hash('sha256', CanonicalJson::encode($event)),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}

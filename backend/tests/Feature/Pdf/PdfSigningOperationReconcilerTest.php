<?php

namespace Tests\Feature\Pdf;

use App\Jobs\ExecutePdfSigningOperation;
use App\Jobs\ResumePdfOperationFromJavaResult;
use App\Models\PdfDocument;
use App\Models\PdfJavaSigningExecution;
use App\Models\PdfOperationOutbox;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningPolicyVersion;
use App\Models\User;
use App\Services\Pdf\CanonicalJson;
use App\Services\Pdf\PdfImmutableFileStore;
use App\Services\Pdf\PdfOperationOrphanFileReconciler;
use App\Services\Pdf\PdfOperationOutboxDispatcher;
use App\Services\Pdf\PdfSigningOperationReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PdfSigningOperationReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_claimed_operation_without_lease_is_redispatched_only_through_a_pending_outbox(): void
    {
        $operation = $this->operation();
        PdfOperationOutbox::query()->create([
            'operation_id' => $operation->id,
            'job_type' => 'execute_pdf_signing_operation',
            'payload_hash' => str_repeat('0', 64),
            'state' => 'pending',
            'attempt_count' => 1,
            'available_at' => now()->subMinute(),
        ]);

        $this->assertSame(1, app(PdfSigningOperationReconciler::class)->sweep());

        $outbox = PdfOperationOutbox::query()->where('operation_id', $operation->id)->sole();
        $this->assertSame('pending', $outbox->state);
        $this->assertNull($outbox->dispatched_at);
        $this->assertSame($this->outboxHash($operation, 'execute_pdf_signing_operation'), $outbox->payload_hash);
        $this->assertDatabaseHas('pdf_signing_operation_events', [
            'operation_id' => $operation->id,
            'event_type' => 'RECONCILER_REDISPATCHED_CLAIMED',
            'reason_code' => 'AUTOMATED_RECOVERY',
        ]);
    }

    public function test_claimed_operation_never_reactivates_a_cancelled_or_dispatched_outbox(): void
    {
        foreach (['cancelled', 'dispatched'] as $state) {
            $operation = $this->operation();
            PdfOperationOutbox::query()->create([
                'operation_id' => $operation->id,
                'job_type' => 'execute_pdf_signing_operation',
                'payload_hash' => $this->outboxHash($operation, 'execute_pdf_signing_operation'),
                'state' => $state,
                'available_at' => now()->subMinute(),
            ]);
        }

        $this->assertSame(0, app(PdfSigningOperationReconciler::class)->sweep());
        $this->assertSame(['cancelled', 'dispatched'], PdfOperationOutbox::query()->orderBy('id')->pluck('state')->all());
        $this->assertDatabaseCount('pdf_signing_operation_events', 0);
    }

    public function test_expired_promoted_operation_preserves_the_final_identity_and_dispatches_commit_replay(): void
    {
        Bus::fake();
        $resultRevisionUuid = (string) Str::uuid();
        $operationUuid = (string) Str::uuid();
        $finalPath = "workflow/revisions/{$resultRevisionUuid}/{$operationUuid}/3/document.pdf";
        $operation = $this->operation([
            'operation_uuid' => $operationUuid,
            'result_revision_uuid' => $resultRevisionUuid,
            'state' => 'promoted',
            'stage' => 'committing',
            'lease_owner' => (string) Str::uuid(),
            'lease_epoch' => 3,
            'lease_expires_at' => now()->subMinute(),
            'promoted_file_path' => $finalPath,
            'result_sha256' => str_repeat('a', 64),
            'result_size' => 1234,
        ]);

        $this->assertSame(1, app(PdfSigningOperationReconciler::class)->sweep());

        $recovered = $operation->refresh();
        $this->assertSame('promoted', $recovered->state);
        $this->assertSame('committing', $recovered->stage);
        $this->assertSame(4, (int) $recovered->lease_epoch);
        $this->assertSame($finalPath, $recovered->promoted_file_path);
        $this->assertTrue($recovered->lease_expires_at->isFuture());
        $this->assertSame(1, app(PdfOperationOutboxDispatcher::class)->dispatchPending());
        Bus::assertDispatched(
            ExecutePdfSigningOperation::class,
            fn (ExecutePdfSigningOperation $job): bool => $job->operationUuid === $operation->operation_uuid,
        );
        $this->assertDatabaseHas('pdf_signing_operation_events', [
            'operation_id' => $operation->id,
            'event_type' => 'RECONCILER_LEASE_TAKEOVER',
        ]);
    }

    public function test_manual_adoption_takeover_uses_only_the_result_resume_job(): void
    {
        Bus::fake();
        $operation = $this->operation([
            'state' => 'processing',
            'stage' => 'java_polling',
            'lease_epoch' => 4,
            'java_request_started_at' => now()->subMinutes(2),
            'java_execution_state' => 'completed',
            'error_retryability' => 'manual_adoption_result_only',
        ]);
        PdfJavaSigningExecution::query()->create([
            'operation_uuid' => $operation->operation_uuid,
            'operation_input_manifest_hash' => $operation->operation_input_manifest_hash,
            'input_fingerprint' => $operation->input_fingerprint,
            'policy_hash' => $operation->policy_hash,
            'authorized_lease_epoch' => 4,
            'claimed_at' => now()->subMinutes(3),
            'private_key_started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
            'terminal_at' => now()->subMinute(),
            'state' => 'completed',
            'result_path' => '/java/results/'.$operation->operation_uuid.'.pdf',
            'result_sha256' => str_repeat('b', 64),
            'result_size' => 5678,
            'result_integrity_state' => 'available',
            'evidence_hold_state' => 'active',
            'evidence_hold_mask' => 5,
        ]);

        $this->assertSame(1, app(PdfSigningOperationReconciler::class)->sweep());

        $this->assertSame(5, (int) $operation->refresh()->lease_epoch);
        $outbox = PdfOperationOutbox::query()->where('operation_id', $operation->id)->sole();
        $this->assertSame('resume_pdf_operation_from_java_result', $outbox->job_type);
        $this->assertSame(1, app(PdfOperationOutboxDispatcher::class)->dispatchPending());
        Bus::assertDispatched(
            ResumePdfOperationFromJavaResult::class,
            fn (ResumePdfOperationFromJavaResult $job): bool => $job->operationUuid === $operation->operation_uuid,
        );
        Bus::assertNotDispatched(ExecutePdfSigningOperation::class);
    }

    public function test_active_leases_and_document_evidence_holds_are_not_taken_over(): void
    {
        $active = $this->operation([
            'state' => 'processing',
            'stage' => 'java_polling',
            'lease_owner' => (string) Str::uuid(),
            'lease_epoch' => 2,
            'lease_expires_at' => now()->addMinute(),
        ]);
        $held = $this->operation([
            'state' => 'processing',
            'stage' => 'java_polling',
            'lease_owner' => (string) Str::uuid(),
            'lease_epoch' => 6,
            'lease_expires_at' => now()->subMinute(),
            'document_evidence_hold_mask' => 8,
        ]);

        $this->assertSame(0, app(PdfSigningOperationReconciler::class)->sweep());
        $this->assertSame(2, (int) $active->refresh()->lease_epoch);
        $this->assertSame(6, (int) $held->refresh()->lease_epoch);
        $this->assertDatabaseCount('pdf_operation_outbox', 0);
        $this->assertDatabaseCount('pdf_signing_operation_events', 0);
    }

    public function test_expired_processing_operation_never_reactivates_a_cancelled_outbox(): void
    {
        $operation = $this->operation([
            'state' => 'processing',
            'stage' => 'java_polling',
            'lease_owner' => (string) Str::uuid(),
            'lease_epoch' => 9,
            'lease_expires_at' => now()->subMinute(),
        ]);
        PdfOperationOutbox::query()->create([
            'operation_id' => $operation->id,
            'job_type' => 'execute_pdf_signing_operation',
            'payload_hash' => $this->outboxHash($operation, 'execute_pdf_signing_operation'),
            'state' => 'cancelled',
            'available_at' => now()->subMinute(),
        ]);

        $this->assertSame(0, app(PdfSigningOperationReconciler::class)->sweep());
        $this->assertSame(9, (int) $operation->refresh()->lease_epoch);
        $this->assertSame('cancelled', PdfOperationOutbox::query()->sole()->state);
        $this->assertDatabaseCount('pdf_signing_operation_events', 0);
    }

    public function test_orphan_reconciler_quarantines_only_old_unreferenced_operation_candidates(): void
    {
        Storage::fake('pdf');
        $operation = $this->operation([
            'state' => 'processing',
            'stage' => 'staging',
            'lease_owner' => (string) Str::uuid(),
            'lease_epoch' => 2,
            'lease_expires_at' => now()->addMinute(),
        ]);
        $oldPath = "workflow/staging/{$operation->operation_uuid}/1/candidate.pdf";
        $currentPath = "workflow/staging/{$operation->operation_uuid}/2/candidate.pdf";
        $oldBytes = '%PDF-1.7 old orphan candidate';
        $currentBytes = '%PDF-1.7 current fenced candidate';
        $files = app(PdfImmutableFileStore::class);
        $files->putBytes($oldBytes, $oldPath);
        $files->putBytes($currentBytes, $currentPath);
        touch(Storage::disk('pdf')->path($oldPath), now()->subMinutes(10)->timestamp);
        touch(Storage::disk('pdf')->path($currentPath), now()->subMinutes(10)->timestamp);
        $fingerprint = hash('sha256', $oldPath);
        $quarantinePath = "workflow/quarantine/orphans/{$operation->operation_uuid}/{$fingerprint}/candidate.pdf";

        $this->assertSame(1, app(PdfOperationOrphanFileReconciler::class)->sweep());

        Storage::disk('pdf')->assertMissing($oldPath);
        Storage::disk('pdf')->assertExists($currentPath);
        Storage::disk('pdf')->assertExists($quarantinePath);
        $this->assertSame($oldBytes, Storage::disk('pdf')->get($quarantinePath));
        $this->assertSame([
            'OPERATION_ORPHAN_QUARANTINE_INTENT',
            'OPERATION_ORPHAN_QUARANTINED',
        ], DB::table('pdf_signing_operation_events')
            ->where('operation_id', $operation->id)
            ->orderBy('id')
            ->pluck('event_type')
            ->all());
        $this->assertDatabaseCount('pdf_files', 0);
    }

    public function test_orphan_quarantine_intent_recovers_after_rename_before_completion_event(): void
    {
        Storage::fake('pdf');
        $operation = $this->operation([
            'state' => 'failed',
            'stage' => 'done',
            'lease_epoch' => 3,
        ]);
        $sourcePath = "workflow/staging/{$operation->operation_uuid}/2/candidate.pdf";
        $bytes = '%PDF-1.7 intent crash candidate';
        $files = app(PdfImmutableFileStore::class);
        $descriptor = $files->putBytes($bytes, $sourcePath);
        $fingerprint = hash('sha256', $sourcePath);
        $quarantinePath = "workflow/quarantine/orphans/{$operation->operation_uuid}/{$fingerprint}/candidate.pdf";
        $intentUuid = (string) Str::uuid();
        DB::table('pdf_signing_operation_events')->insert([
            'event_uuid' => $intentUuid,
            'operation_id' => $operation->id,
            'event_type' => 'OPERATION_ORPHAN_QUARANTINE_INTENT',
            'actor_user_id' => null,
            'reason_code' => 'ORPHAN_QUARANTINE_INTENT',
            'resolution_fingerprint' => $fingerprint,
            'event_payload' => json_encode([
                'operation_uuid' => $operation->operation_uuid,
                'kind' => 'staging',
                'lease_epoch' => 2,
                'source_path' => $sourcePath,
                'quarantine_path' => $quarantinePath,
                'sha256' => $descriptor['sha256'],
                'size' => $descriptor['size'],
            ], JSON_THROW_ON_ERROR),
            'previous_event_hash' => null,
            'event_hash' => str_repeat('a', 64),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/PdfRetirementCrashWorker.php'),
            'move',
            Storage::disk('pdf')->path($sourcePath),
            Storage::disk('pdf')->path($quarantinePath),
        ]);
        $process->setTimeout(10);
        $killed = false;
        try {
            $process->run();
        } catch (ProcessSignaledException) {
            $killed = $process->getTermSignal() === 9;
        }
        $this->assertTrue($killed || $process->getExitCode() === 137);
        $this->assertStringContainsString('retirement-file-action-durable', $process->getOutput());

        $this->assertSame(1, app(PdfOperationOrphanFileReconciler::class)->sweep());

        Storage::disk('pdf')->assertMissing($sourcePath);
        Storage::disk('pdf')->assertExists($quarantinePath);
        $this->assertDatabaseHas('pdf_signing_operation_events', [
            'operation_id' => $operation->id,
            'event_type' => 'OPERATION_ORPHAN_QUARANTINED',
            'resolution_fingerprint' => $fingerprint,
        ]);
        $completionPayload = json_decode((string) DB::table('pdf_signing_operation_events')
            ->where('event_type', 'OPERATION_ORPHAN_QUARANTINED')
            ->value('event_payload'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($intentUuid, $completionPayload['intent_event_uuid']);
    }

    public function test_unregistered_operation_orphan_is_quarantined_without_becoming_a_revision(): void
    {
        Storage::fake('pdf');
        $operationUuid = (string) Str::uuid();
        $revisionUuid = (string) Str::uuid();
        $sourcePath = "workflow/revisions/{$revisionUuid}/{$operationUuid}/1/document.pdf";
        $bytes = '%PDF-1.7 unregistered operation orphan';
        app(PdfImmutableFileStore::class)->putBytes($bytes, $sourcePath);
        touch(Storage::disk('pdf')->path($sourcePath), now()->subMinutes(10)->timestamp);
        $fingerprint = hash('sha256', $sourcePath);
        $quarantinePath = "workflow/quarantine/orphans/{$operationUuid}/{$fingerprint}/document.pdf";

        $this->assertSame(1, app(PdfOperationOrphanFileReconciler::class)->sweep());

        Storage::disk('pdf')->assertMissing($sourcePath);
        Storage::disk('pdf')->assertExists($quarantinePath);
        $this->assertSame($bytes, Storage::disk('pdf')->get($quarantinePath));
        $this->assertDatabaseCount('pdf_files', 0);
        $this->assertDatabaseCount('pdf_signing_operation_events', 0);
    }

    public function test_manual_review_evidence_candidate_is_never_classified_as_an_orphan(): void
    {
        Storage::fake('pdf');
        $operation = $this->operation([
            'state' => 'manual_review',
            'stage' => 'done',
            'lease_epoch' => 5,
            'document_evidence_hold_mask' => 8,
        ]);
        $sourcePath = "workflow/staging/{$operation->operation_uuid}/4/candidate.pdf";
        app(PdfImmutableFileStore::class)->putBytes('%PDF-1.7 held manual evidence', $sourcePath);
        touch(Storage::disk('pdf')->path($sourcePath), now()->subMinutes(10)->timestamp);

        $this->assertSame(0, app(PdfOperationOrphanFileReconciler::class)->sweep());

        Storage::disk('pdf')->assertExists($sourcePath);
        $this->assertDatabaseCount('pdf_signing_operation_events', 0);
    }

    public function test_active_takeover_preserves_the_previous_complete_staging_candidate_for_adoption(): void
    {
        Storage::fake('pdf');
        $operation = $this->operation([
            'state' => 'processing',
            'stage' => 'java_polling',
            'lease_owner' => (string) Str::uuid(),
            'lease_epoch' => 3,
            'lease_expires_at' => now()->addMinute(),
        ]);
        DB::table('pdf_signing_operation_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'operation_id' => $operation->id,
            'event_type' => 'RECONCILER_LEASE_TAKEOVER',
            'actor_user_id' => null,
            'reason_code' => 'AUTOMATED_RECOVERY',
            'resolution_fingerprint' => null,
            'event_payload' => json_encode([
                'old_lease_epoch' => 2,
                'new_lease_epoch' => 3,
                'old_stage' => 'staging',
                'new_stage' => 'java_polling',
            ], JSON_THROW_ON_ERROR),
            'previous_event_hash' => null,
            'event_hash' => str_repeat('b', 64),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourcePath = "workflow/staging/{$operation->operation_uuid}/2/candidate.pdf";
        app(PdfImmutableFileStore::class)->putBytes('%PDF-1.7 takeover candidate', $sourcePath);
        touch(Storage::disk('pdf')->path($sourcePath), now()->subMinutes(10)->timestamp);

        $this->assertSame(0, app(PdfOperationOrphanFileReconciler::class)->sweep());

        Storage::disk('pdf')->assertExists($sourcePath);
        $this->assertDatabaseCount('pdf_signing_operation_events', 1);
    }

    /** @param array<string, mixed> $overrides */
    private function operation(array $overrides = []): PdfSigningOperation
    {
        $actor = User::factory()->create();
        $document = PdfDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'document_public_id' => 'DOC-'.Str::random(12),
            'organization_scope' => 'test',
            'authoritative_report_number' => 'R-'.Str::random(12),
            'normalized_report_number' => 'R-'.Str::random(20),
            'created_by_id' => $actor->id,
        ]);
        $manifest = ['profile' => 'B-T', 'version' => (string) Str::uuid()];
        $policy = PdfSigningPolicyVersion::query()->create([
            'version_uuid' => (string) Str::uuid(),
            'policy_hash' => hash('sha256', CanonicalJson::encode($manifest)),
            'immutable_at' => now(),
            'pades_profile' => 'B-T',
            'digest_algorithm_oid' => '2.16.840.1.101.3.4.2.1',
            'signature_algorithm_oid' => '1.2.840.113549.1.1.11',
            'organization_certificate_fingerprints' => [str_repeat('d', 64)],
            'signing_material_version' => 'test-v1',
            'key_locator' => 'test',
            'tsa_url_set' => ['http://tsa.invalid'],
            'tsa_timeout_seconds' => 10,
            'trust_bundle_hash' => str_repeat('e', 64),
            'revocation_policy' => ['mode' => 'required'],
            'reserved_size' => 32768,
            'pre_private_key_retry_backoff_seconds' => [2],
            'pre_private_key_retryable_error_codes' => ['DB_UNAVAILABLE'],
            'java_execution_registration_timeout_seconds' => 15,
            'java_execution_timeout_seconds' => 90,
            'java_status_poll_policy' => ['initial' => 2, 'max' => 10],
            'java_result_min_bytes_per_second' => 1048576,
            'java_result_read_timeout_seconds' => 60,
            'generated_revision_max_bytes' => 33554432,
            'max_signature_increment_bytes' => 2097152,
            'policy_manifest' => $manifest,
            'config_bundle_hash' => str_repeat('f', 64),
        ]);

        return PdfSigningOperation::query()->create(array_merge([
            'operation_uuid' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_scope_key' => (string) Str::uuid(),
            'scope_type' => 'request',
            'actor_user_id' => $actor->id,
            'document_id' => $document->id,
            'action' => 'fill_signature_field',
            'input_fingerprint' => str_repeat('1', 64),
            'operation_input_manifest_hash' => str_repeat('2', 64),
            'signing_policy_version_id' => $policy->id,
            'policy_hash' => $policy->policy_hash,
            'state' => 'claimed',
            'stage' => 'awaiting_dispatch',
            'audit_context' => [],
            'audit_context_hash' => str_repeat('3', 64),
        ], $overrides));
    }

    private function outboxHash(PdfSigningOperation $operation, string $jobType): string
    {
        return hash('sha256', CanonicalJson::encode([
            'job_type' => $jobType,
            'operation_uuid' => $operation->operation_uuid,
        ]));
    }
}

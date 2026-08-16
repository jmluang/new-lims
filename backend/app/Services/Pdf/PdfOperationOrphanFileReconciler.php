<?php

namespace App\Services\Pdf;

use App\Models\PdfFile;
use App\Models\PdfJavaSigningExecution;
use App\Models\PdfSignatureAppearanceArtifact;
use App\Models\PdfSigningOperation;
use App\Models\PdfSourceUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class PdfOperationOrphanFileReconciler
{
    private const INTENT_EVENT = 'OPERATION_ORPHAN_QUARANTINE_INTENT';

    private const COMPLETED_EVENT = 'OPERATION_ORPHAN_QUARANTINED';

    private const DISCOVERY_GRACE_SECONDS = 300;

    public function __construct(private readonly PdfImmutableFileStore $files) {}

    public function sweep(int $limit = 100): int
    {
        $limit = max(1, min($limit, 1000));
        $completed = $this->resumeIntents($limit);

        foreach ($this->discoverCandidates($limit - $completed) as $candidate) {
            if ($completed >= $limit) {
                break;
            }
            try {
                $descriptor = $this->files->inspectImmutableFile($candidate['source_path']);
                $intent = $this->createIntentIfOrphan($candidate, $descriptor);
                if ($intent === null) {
                    continue;
                }
                $this->executeIntent($intent);
                $completed++;
            } catch (Throwable $exception) {
                Log::error('PDF operation orphan quarantine failed closed.', [
                    'source_path' => $candidate['source_path'],
                    'operation_uuid' => $candidate['operation_uuid'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $completed;
    }

    private function resumeIntents(int $limit): int
    {
        if ($limit < 1) {
            return 0;
        }
        $completed = 0;
        $intents = DB::table('pdf_signing_operation_events')
            ->where('event_type', self::INTENT_EVENT)
            ->orderBy('id')
            ->limit($limit * 4)
            ->get();
        foreach ($intents as $intent) {
            if ($completed >= $limit
                || $this->completionExists((int) $intent->operation_id, (string) $intent->resolution_fingerprint)) {
                continue;
            }
            try {
                $payload = $this->validatedIntentPayload((string) $intent->event_payload);
                $this->executeIntent([
                    'operation_id' => (int) $intent->operation_id,
                    'operation_uuid' => $payload['operation_uuid'],
                    'intent_event_uuid' => (string) $intent->event_uuid,
                    'resolution_fingerprint' => (string) $intent->resolution_fingerprint,
                    'source_path' => $payload['source_path'],
                    'quarantine_path' => $payload['quarantine_path'],
                    'sha256' => $payload['sha256'],
                    'size' => $payload['size'],
                ]);
                $completed++;
            } catch (Throwable $exception) {
                Log::error('PDF operation orphan quarantine intent remains unresolved.', [
                    'intent_event_uuid' => $intent->event_uuid,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $completed;
    }

    /**
     * @param  array{kind: 'staging'|'final', operation_uuid: string, lease_epoch: int, source_path: string, revision_uuid: ?string}  $candidate
     * @param  array{path: string, absolute_path: string, sha256: string, size: int}  $descriptor
     * @return array{operation_id: ?int, operation_uuid: string, intent_event_uuid: ?string, resolution_fingerprint: string, source_path: string, quarantine_path: string, sha256: string, size: int}|null
     */
    private function createIntentIfOrphan(array $candidate, array $descriptor): ?array
    {
        return DB::transaction(function () use ($candidate, $descriptor): ?array {
            $operation = PdfSigningOperation::query()
                ->where('operation_uuid', $candidate['operation_uuid'])
                ->lockForUpdate()
                ->first();
            $execution = $operation === null ? null : PdfJavaSigningExecution::query()
                ->where('operation_uuid', $operation->operation_uuid)
                ->lockForUpdate()
                ->first();
            $scope = $operation === null ? null : $this->lockScope($operation);
            if ($this->candidateIsReferenced($candidate, $operation, $execution, $scope)) {
                return null;
            }
            $fingerprint = hash('sha256', $candidate['source_path']);
            $quarantinePath = "workflow/quarantine/orphans/{$candidate['operation_uuid']}/{$fingerprint}/"
                .($candidate['kind'] === 'staging' ? 'candidate.pdf' : 'document.pdf');
            if ($operation === null) {
                return [
                    'operation_id' => null,
                    'operation_uuid' => $candidate['operation_uuid'],
                    'intent_event_uuid' => null,
                    'resolution_fingerprint' => $fingerprint,
                    'source_path' => $candidate['source_path'],
                    'quarantine_path' => $quarantinePath,
                    'sha256' => $descriptor['sha256'],
                    'size' => $descriptor['size'],
                ];
            }
            $existing = DB::table('pdf_signing_operation_events')
                ->where('operation_id', $operation->id)
                ->where('event_type', self::INTENT_EVENT)
                ->where('resolution_fingerprint', $fingerprint)
                ->orderByDesc('id')
                ->first();
            if ($existing !== null) {
                if ($this->completionExists($operation->id, $fingerprint)) {
                    return null;
                }
                $payload = $this->validatedIntentPayload((string) $existing->event_payload);

                return [
                    'operation_id' => $operation->id,
                    'operation_uuid' => $operation->operation_uuid,
                    'intent_event_uuid' => (string) $existing->event_uuid,
                    'resolution_fingerprint' => $fingerprint,
                    'source_path' => $payload['source_path'],
                    'quarantine_path' => $payload['quarantine_path'],
                    'sha256' => $payload['sha256'],
                    'size' => $payload['size'],
                ];
            }
            $intentUuid = (string) Str::uuid();
            $payload = [
                'operation_uuid' => $operation->operation_uuid,
                'kind' => $candidate['kind'],
                'lease_epoch' => $candidate['lease_epoch'],
                'source_path' => $candidate['source_path'],
                'quarantine_path' => $quarantinePath,
                'sha256' => $descriptor['sha256'],
                'size' => $descriptor['size'],
            ];
            $this->appendEvent(
                $operation,
                $intentUuid,
                self::INTENT_EVENT,
                'ORPHAN_QUARANTINE_INTENT',
                $fingerprint,
                $payload,
            );

            return [
                'operation_id' => $operation->id,
                'operation_uuid' => $operation->operation_uuid,
                'intent_event_uuid' => $intentUuid,
                'resolution_fingerprint' => $fingerprint,
                'source_path' => $candidate['source_path'],
                'quarantine_path' => $quarantinePath,
                'sha256' => $descriptor['sha256'],
                'size' => $descriptor['size'],
            ];
        }, 3);
    }

    /**
     * @param  array{operation_id: ?int, operation_uuid: string, intent_event_uuid: ?string, resolution_fingerprint: string, source_path: string, quarantine_path: string, sha256: string, size: int}  $intent
     */
    private function executeIntent(array $intent): void
    {
        $this->files->quarantineOperationOrphan(
            $intent['source_path'],
            $intent['quarantine_path'],
            $intent['sha256'],
            $intent['size'],
        );
        if ($intent['operation_id'] === null) {
            Log::warning('Unregistered PDF operation orphan moved to quarantine.', [
                'operation_uuid' => $intent['operation_uuid'],
                'source_path' => $intent['source_path'],
                'quarantine_path' => $intent['quarantine_path'],
                'sha256' => $intent['sha256'],
                'size' => $intent['size'],
            ]);

            return;
        }
        DB::transaction(function () use ($intent): void {
            $operation = PdfSigningOperation::query()->lockForUpdate()->findOrFail($intent['operation_id']);
            if ($this->completionExists($operation->id, $intent['resolution_fingerprint'])) {
                return;
            }
            $this->appendEvent(
                $operation,
                (string) Str::uuid(),
                self::COMPLETED_EVENT,
                'ORPHAN_QUARANTINED',
                $intent['resolution_fingerprint'],
                [
                    'intent_event_uuid' => $intent['intent_event_uuid'],
                    'source_path' => $intent['source_path'],
                    'quarantine_path' => $intent['quarantine_path'],
                    'sha256' => $intent['sha256'],
                    'size' => $intent['size'],
                ],
            );
        }, 3);
    }

    /**
     * @return list<array{kind: 'staging'|'final', operation_uuid: string, lease_epoch: int, source_path: string, revision_uuid: ?string}>
     */
    private function discoverCandidates(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        $diskRoot = rtrim(Storage::disk('pdf')->path(''), DIRECTORY_SEPARATOR);
        $candidates = [];
        foreach (['workflow/staging', 'workflow/revisions'] as $relativeRoot) {
            $root = Storage::disk('pdf')->path($relativeRoot);
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $entry) {
                if (! $entry->isFile() && ! $entry->isLink()) {
                    continue;
                }
                $stat = @lstat($entry->getPathname());
                if ($stat === false || (int) $stat['mtime'] > time() - self::DISCOVERY_GRACE_SECONDS) {
                    continue;
                }
                $sourcePath = str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    substr($entry->getPathname(), strlen($diskRoot) + 1),
                );
                $candidate = $this->parseCandidate($sourcePath);
                if ($candidate !== null) {
                    $candidates[$sourcePath] = $candidate;
                }
            }
        }
        ksort($candidates);

        return array_slice(array_values($candidates), 0, $limit);
    }

    /** @return array{kind: 'staging'|'final', operation_uuid: string, lease_epoch: int, source_path: string, revision_uuid: ?string}|null */
    private function parseCandidate(string $sourcePath): ?array
    {
        if (preg_match(
            '#^workflow/staging/([0-9a-f-]{36})/([0-9]+)/candidate\.pdf$#',
            $sourcePath,
            $matches,
        ) === 1) {
            return [
                'kind' => 'staging',
                'operation_uuid' => $matches[1],
                'lease_epoch' => (int) $matches[2],
                'source_path' => $sourcePath,
                'revision_uuid' => null,
            ];
        }
        if (preg_match(
            '#^workflow/revisions/([0-9a-f-]{36})/([0-9a-f-]{36})/([0-9]+)/document\.pdf$#',
            $sourcePath,
            $matches,
        ) === 1) {
            return [
                'kind' => 'final',
                'operation_uuid' => $matches[2],
                'lease_epoch' => (int) $matches[3],
                'source_path' => $sourcePath,
                'revision_uuid' => $matches[1],
            ];
        }

        return null;
    }

    /**
     * @param  array{kind: 'staging'|'final', operation_uuid: string, lease_epoch: int, source_path: string, revision_uuid: ?string}  $candidate
     * @param  array{document: object|null, appearance_hold: bool}|null  $scope
     */
    private function candidateIsReferenced(
        array $candidate,
        ?PdfSigningOperation $operation,
        ?PdfJavaSigningExecution $execution,
        ?array $scope,
    ): bool {
        $sourcePath = $candidate['source_path'];
        $absolutePath = Storage::disk('pdf')->path($sourcePath);
        if (PdfFile::query()->where('file_path', $sourcePath)->lockForUpdate()->first() !== null
            || PdfSourceUpload::query()->where('stored_path', $sourcePath)->lockForUpdate()->first() !== null
            || PdfSignatureAppearanceArtifact::query()
                ->where('file_path', $sourcePath)
                ->orWhere('retirement_staged_path', $sourcePath)
                ->lockForUpdate()
                ->first() !== null
            || PdfJavaSigningExecution::query()
                ->whereIn('result_path', [$sourcePath, $absolutePath])
                ->orWhereIn('retirement_staged_path', [$sourcePath, $absolutePath])
                ->lockForUpdate()
                ->first() !== null
            || PdfSigningOperation::query()
                ->where('promoted_file_path', $sourcePath)
                ->lockForUpdate()
                ->first() !== null) {
            return true;
        }
        if ($operation === null) {
            return false;
        }
        if ((int) $operation->document_evidence_hold_mask !== 0
            || in_array($operation->state, ['manual_review', 'irreversible_failed'], true)
            || ($execution !== null
                && ((int) $execution->evidence_hold_mask !== 0
                    || $execution->retirement_phase !== 'none'
                    || ($execution->legal_hold_until !== null
                        && now()->lt($execution->legal_hold_until))))
            || ($scope['document'] !== null
                && ((int) $scope['document']->evidence_hold_mask !== 0
                    || (int) $scope['document']->integrity_hold_mask !== 0
                    || ($scope['document']->legal_hold_until !== null
                        && now()->lt($scope['document']->legal_hold_until))))
            || $scope['appearance_hold']) {
            return true;
        }
        if ($candidate['kind'] === 'staging') {
            if ($operation->state === 'processing'
                && in_array($operation->stage, ['staging', 'verifying', 'promoting'], true)
                && (int) $operation->lease_epoch === $candidate['lease_epoch']) {
                return true;
            }

            return $this->isTakeoverRecoveryCandidate($operation, $candidate);
        }

        if ($operation->state === 'processing'
            && $operation->stage === 'promoting'
            && (int) $operation->lease_epoch === $candidate['lease_epoch']
            && $operation->result_revision_uuid === $candidate['revision_uuid']
            && ($execution === null || in_array($execution->result_integrity_state, ['available', 'missing', 'breached'], true))) {
            return true;
        }

        return $this->isTakeoverRecoveryCandidate($operation, $candidate);
    }

    /** @param array{kind: 'staging'|'final', operation_uuid: string, lease_epoch: int, source_path: string, revision_uuid: ?string} $candidate */
    private function isTakeoverRecoveryCandidate(
        PdfSigningOperation $operation,
        array $candidate,
    ): bool {
        if ($operation->state !== 'processing'
            || $operation->lease_owner === null
            || $operation->lease_expires_at === null
            || $operation->lease_expires_at->lte(now())) {
            return false;
        }
        $eventPayload = DB::table('pdf_signing_operation_events')
            ->where('operation_id', $operation->id)
            ->where('event_type', 'RECONCILER_LEASE_TAKEOVER')
            ->orderByDesc('id')
            ->value('event_payload');
        if (! is_string($eventPayload)) {
            return false;
        }
        $payload = json_decode($eventPayload, true);

        return is_array($payload)
            && in_array($payload['old_stage'] ?? null, ['staging', 'verifying', 'promoting'], true)
            && (int) ($payload['old_lease_epoch'] ?? -1) === $candidate['lease_epoch']
            && ($candidate['kind'] !== 'final'
                || $operation->result_revision_uuid === $candidate['revision_uuid']);
    }

    /** @return array{document: object|null, appearance_hold: bool} */
    private function lockScope(PdfSigningOperation $operation): array
    {
        $document = DB::table('pdf_documents')->where('id', $operation->document_id)->lockForUpdate()->first();
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
        $appearances = PdfSignatureAppearanceArtifact::query()
            ->where('claimed_by_operation_id', $operation->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return [
            'document' => $document,
            'appearance_hold' => $appearances->contains(
                fn (PdfSignatureAppearanceArtifact $appearance): bool => (int) $appearance->evidence_hold_mask !== 0,
            ),
        ];
    }

    private function completionExists(int $operationId, string $fingerprint): bool
    {
        return DB::table('pdf_signing_operation_events')
            ->where('operation_id', $operationId)
            ->where('event_type', self::COMPLETED_EVENT)
            ->where('resolution_fingerprint', $fingerprint)
            ->exists();
    }

    /** @return array{operation_uuid: string, source_path: string, quarantine_path: string, sha256: string, size: int} */
    private function validatedIntentPayload(string $json): array
    {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload)
            || ! is_string($payload['operation_uuid'] ?? null)
            || ! is_string($payload['source_path'] ?? null)
            || ! is_string($payload['quarantine_path'] ?? null)
            || ! is_string($payload['sha256'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/', $payload['sha256']) !== 1
            || ! is_int($payload['size'] ?? null)
            || $payload['size'] < 0) {
            throw new \RuntimeException('Operation orphan quarantine intent payload is invalid.');
        }

        return [
            'operation_uuid' => $payload['operation_uuid'],
            'source_path' => $payload['source_path'],
            'quarantine_path' => $payload['quarantine_path'],
            'sha256' => $payload['sha256'],
            'size' => $payload['size'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function appendEvent(
        PdfSigningOperation $operation,
        string $eventUuid,
        string $eventType,
        string $reasonCode,
        string $resolutionFingerprint,
        array $payload,
    ): void {
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
            'event_uuid' => $eventUuid,
            'operation_id' => $operation->id,
            'event_type' => $eventType,
            'actor_user_id' => null,
            'reason_code' => $reasonCode,
            'resolution_fingerprint' => $resolutionFingerprint,
            'event_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_event_hash' => $previousHash,
            'event_hash' => hash('sha256', CanonicalJson::encode($event)),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}

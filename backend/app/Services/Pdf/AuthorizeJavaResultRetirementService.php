<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfJavaSigningExecution;
use App\Models\PdfSigningOperation;
use App\Models\PdfSigningPolicyVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class AuthorizeJavaResultRetirementService
{
    public function __construct(
        private readonly PdfImmutableFileStore $files,
        private readonly PdfRendererClient $renderer,
    ) {}

    public function sweep(int $limit = 100): int
    {
        $authorized = 0;
        $ids = PdfSigningOperation::query()
            ->where('state', 'completed')
            ->whereNotNull('result_revision_id')
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                if ($this->authorize((int) $id)) {
                    $authorized++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $authorized;
    }

    private function authorize(int $operationId): bool
    {
        $candidate = PdfSigningOperation::query()->find($operationId);
        $revision = $candidate?->result_revision_id
            ? PdfFile::query()->find($candidate->result_revision_id)
            : null;
        if ($candidate === null || $revision === null) {
            return false;
        }

        $path = $this->files->verifiedAbsolutePath(
            $revision->file_path,
            $revision->sha256_hash,
            (int) $revision->file_size,
        );
        $verification = $this->renderer->verifySignaturePdf($path);
        if (($verification['documentCurrentState'] ?? null) !== 'valid'
            || count($verification['signatures'] ?? []) < 1) {
            throw new RuntimeException('Formal PDF revision is not eligible for Java result retirement.');
        }

        return DB::transaction(function () use ($operationId, $revision): bool {
            $operation = PdfSigningOperation::query()->lockForUpdate()->findOrFail($operationId);
            $execution = PdfJavaSigningExecution::query()
                ->where('operation_uuid', $operation->operation_uuid)
                ->lockForUpdate()
                ->first();
            $document = PdfDocument::query()->lockForUpdate()->findOrFail($operation->document_id);
            $lockedRevision = PdfFile::query()->lockForUpdate()->findOrFail($operation->result_revision_id);

            if ($operation->state !== 'completed'
                || $execution === null
                || $execution->state !== 'completed'
                || $execution->result_integrity_state !== 'available'
                || $execution->retirement_phase !== 'none'
                || (int) $execution->evidence_hold_mask !== 0
                || $execution->evidence_hold_state !== 'none'
                || ($execution->legal_hold_until !== null && $execution->legal_hold_until->isFuture())
                || $execution->retention_until === null || $execution->retention_until->isFuture()
                || $document->integrity_state !== 'ok' || (int) $document->integrity_hold_mask !== 0
                || $document->evidence_hold_state !== 'none' || (int) $document->evidence_hold_mask !== 0
                || $lockedRevision->integrity_state !== 'ready'
                || $lockedRevision->sha256_hash !== $revision->sha256_hash
                || (int) $lockedRevision->file_size !== (int) $revision->file_size
                || $operation->result_sha256 !== $lockedRevision->sha256_hash
                || (int) $operation->result_size !== (int) $lockedRevision->file_size
                || $execution->result_sha256 !== $operation->result_sha256
                || (int) $execution->result_size !== (int) $operation->result_size) {
                $this->clearAuthorization($operation);

                return false;
            }

            $this->files->verifiedAbsolutePath(
                $lockedRevision->file_path,
                $lockedRevision->sha256_hash,
                (int) $lockedRevision->file_size,
            );
            $policy = PdfSigningPolicyVersion::query()->findOrFail($operation->signing_policy_version_id);
            $now = now();
            $existing = $operation->result_retirement_authorization_manifest;
            if ($operation->result_retirement_authorization_hash !== null
                && $operation->result_retirement_authorization_expires_at?->isFuture()
                && is_array($existing)
                && ($existing['execution_result_path'] ?? null) === $execution->result_path
                && ($existing['execution_result_sha256'] ?? null) === $execution->result_sha256
                && (int) ($existing['execution_result_size'] ?? -1) === (int) $execution->result_size
                && ($existing['formal_revision_uuid'] ?? null) === $lockedRevision->revision_uuid
                && ($existing['formal_revision_sha256'] ?? null) === $lockedRevision->sha256_hash) {
                return false;
            }
            $notBefore = $now->copy();
            $expiresAt = $now->copy()->addSeconds((int) $policy->retirement_authorization_ttl_seconds);
            $manifest = [
                'operation_uuid' => $operation->operation_uuid,
                'execution_result_path' => $execution->result_path,
                'execution_result_sha256' => $execution->result_sha256,
                'execution_result_size' => (int) $execution->result_size,
                'formal_revision_uuid' => $lockedRevision->revision_uuid,
                'formal_revision_sha256' => $lockedRevision->sha256_hash,
                'grace_seconds' => (int) $policy->evidence_retirement_grace_seconds,
                'not_before' => $notBefore->toISOString(),
                'expires_at' => $expiresAt->toISOString(),
            ];
            $hash = hash('sha256', CanonicalJson::encode($manifest));

            if ($operation->result_retirement_authorization_hash === $hash) {
                return false;
            }

            $operation->update([
                'result_retirement_not_before' => $notBefore,
                'result_retirement_authorized_at' => $now,
                'result_retirement_authorization_expires_at' => $expiresAt,
                'result_retirement_authorization_manifest' => $manifest,
                'result_retirement_authorization_hash' => $hash,
            ]);

            return true;
        }, 3);
    }

    private function clearAuthorization(PdfSigningOperation $operation): void
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
}

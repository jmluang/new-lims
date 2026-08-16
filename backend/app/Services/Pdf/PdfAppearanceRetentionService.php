<?php

namespace App\Services\Pdf;

use App\Models\PdfSignatureAppearanceArtifact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class PdfAppearanceRetentionService
{
    private const NON_TERMINAL_OPERATION_STATES = ['claimed', 'processing', 'promoted', 'manual_review'];

    /** @return array{stream: resource, sha256: string, size: int} */
    public function openVerifiedDescriptor(PdfSignatureAppearanceArtifact $artifact): array
    {
        return DB::transaction(function () use ($artifact): array {
            $locked = $this->lockArtifactWithScope($artifact->id);
            if ($locked->file_path === null || $locked->deleted_at !== null
                || $locked->retirement_state === 'retired') {
                throw new RuntimeException('Appearance evidence is not available.');
            }
            $path = $this->absolute($locked->file_path);
            if (is_link($path)) {
                throw new RuntimeException('Appearance evidence must not be a symbolic link.');
            }
            $stream = @fopen($path, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Appearance evidence descriptor cannot be opened.');
            }
            try {
                $stat = fstat($stream);
                if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)) {
                    throw new RuntimeException('Appearance evidence is not a regular file.');
                }
                $hash = hash_init('sha256');
                $size = hash_update_stream($hash, $stream);
                $sha256 = hash_final($hash);
                if ($size === false || ! hash_equals($locked->canonical_image_sha256, $sha256)) {
                    throw new RuntimeException('Appearance evidence descriptor failed SHA-256 verification.');
                }
                rewind($stream);

                return ['stream' => $stream, 'sha256' => $sha256, 'size' => $size];
            } catch (\Throwable $exception) {
                fclose($stream);
                throw $exception;
            }
        }, 3);
    }

    public function sweep(int $limit = 100): int
    {
        $limit = max(1, min($limit, 1000));
        $changed = 0;
        $changed += $this->startStageIntents($limit);
        $changed += $this->applyStageIntents($limit);
        $changed += $this->restoreHeldRetirements($limit);
        $changed += $this->startPurgeIntents($limit);
        $changed += $this->applyPurgeIntents($limit);

        return $changed;
    }

    private function restoreHeldRetirements(int $limit): int
    {
        $ids = PdfSignatureAppearanceArtifact::query()
            ->whereIn('retirement_state', ['staged', 'purge_intent'])
            ->where(function ($query): void {
                $query->where('evidence_hold_state', 'active')
                    ->orWhere('evidence_hold_mask', '<>', 0)
                    ->orWhere('legal_hold_until', '>', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $changed = 0;

        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id): int {
                $artifact = $this->lockArtifactWithScope((int) $id);
                if (! in_array($artifact->retirement_state, ['staged', 'purge_intent'], true)
                    || ! $this->hasHoldOrReference($artifact)) {
                    return 0;
                }
                $this->restoreOrCancel($artifact);

                return 1;
            }, 3);
        }

        return $changed;
    }

    private function startStageIntents(int $limit): int
    {
        $ids = PdfSignatureAppearanceArtifact::query()
            ->where('retirement_state', 'none')
            ->where('evidence_hold_state', 'none')
            ->whereNotNull('retention_until')
            ->where('retention_until', '<=', now())
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $changed = 0;

        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id): int {
                $artifact = $this->lockArtifactWithScope((int) $id);
                if (! $this->eligibleForRetirement($artifact)) {
                    return 0;
                }
                $epoch = (int) $artifact->retirement_epoch + 1;
                $extension = pathinfo((string) $artifact->file_path, PATHINFO_EXTENSION) ?: 'png';
                $artifact->update([
                    'retirement_state' => 'stage_intent',
                    'retirement_epoch' => $epoch,
                    'retirement_staged_path' => "workflow/appearance-retirement/{$artifact->appearance_uuid}-{$epoch}.{$extension}",
                    'retirement_staged_at' => null,
                    'retirement_purge_not_before' => null,
                    'lock_version' => $artifact->lock_version + 1,
                ]);
                $this->appendRetirementAudit($artifact, 'APPEARANCE_RETIRE_STAGE_INTENT', 'none');

                return 1;
            }, 3);
        }

        return $changed;
    }

    private function applyStageIntents(int $limit): int
    {
        $ids = PdfSignatureAppearanceArtifact::query()
            ->where('retirement_state', 'stage_intent')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $changed = 0;

        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id): int {
                $artifact = $this->lockArtifactWithScope((int) $id);
                if ($artifact->retirement_state !== 'stage_intent') {
                    return 0;
                }
                if (! $this->eligibleForRetirement($artifact, allowIntent: true)) {
                    $this->restoreOrCancel($artifact);

                    return 1;
                }
                $canonical = $this->absolute((string) $artifact->file_path);
                $staged = $this->absolute((string) $artifact->retirement_staged_path);
                $canonicalExists = is_file($canonical);
                $stagedExists = is_file($staged);
                if ($canonicalExists && $stagedExists) {
                    throw new RuntimeException('Appearance retirement has ambiguous duplicate bytes.');
                }
                if ($canonicalExists) {
                    $this->assertHash($canonical, $artifact->canonical_image_sha256);
                    $this->atomicMove($canonical, $staged);
                } elseif ($stagedExists) {
                    $this->assertHash($staged, $artifact->canonical_image_sha256);
                } else {
                    throw new RuntimeException('Appearance retirement source bytes are missing.');
                }
                $artifact->update([
                    'retirement_state' => 'staged',
                    'retirement_staged_at' => now(),
                    'retirement_purge_not_before' => now()->addDay(),
                    'lock_version' => $artifact->lock_version + 1,
                ]);
                $this->appendRetirementAudit($artifact, 'APPEARANCE_RETIRE_STAGED', 'stage_intent');

                return 1;
            }, 3);
        }

        return $changed;
    }

    private function startPurgeIntents(int $limit): int
    {
        $ids = PdfSignatureAppearanceArtifact::query()
            ->where('retirement_state', 'staged')
            ->where('retirement_purge_not_before', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $changed = 0;

        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id): int {
                $artifact = $this->lockArtifactWithScope((int) $id);
                if ($artifact->retirement_state !== 'staged') {
                    return 0;
                }
                if ($this->hasHoldOrReference($artifact)) {
                    $this->restoreOrCancel($artifact);

                    return 1;
                }
                $artifact->update([
                    'retirement_state' => 'purge_intent',
                    'lock_version' => $artifact->lock_version + 1,
                ]);
                $this->appendRetirementAudit($artifact, 'APPEARANCE_RETIRE_PURGE_INTENT', 'staged');

                return 1;
            }, 3);
        }

        return $changed;
    }

    private function applyPurgeIntents(int $limit): int
    {
        $ids = PdfSignatureAppearanceArtifact::query()
            ->where('retirement_state', 'purge_intent')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $changed = 0;

        foreach ($ids as $id) {
            $changed += DB::transaction(function () use ($id): int {
                $artifact = $this->lockArtifactWithScope((int) $id);
                if ($artifact->retirement_state !== 'purge_intent') {
                    return 0;
                }
                if ($this->hasHoldOrReference($artifact)) {
                    $this->restoreOrCancel($artifact);

                    return 1;
                }
                $canonical = $this->absolute((string) $artifact->file_path);
                if (file_exists($canonical) || is_link($canonical)) {
                    throw new RuntimeException('Appearance purge found unexpected canonical bytes.');
                }
                $staged = $this->absolute((string) $artifact->retirement_staged_path);
                if (file_exists($staged) || is_link($staged)) {
                    $this->assertHash($staged, $artifact->canonical_image_sha256);
                    if (! unlink($staged)) {
                        throw new RuntimeException('Unable to purge staged appearance bytes.');
                    }
                    $this->syncDirectory(dirname($staged));
                }
                $stagedPath = $artifact->retirement_staged_path;
                $artifact->update([
                    'state' => 'expired',
                    'retirement_state' => 'retired',
                    'deleted_at' => now(),
                    'retirement_staged_path' => null,
                    'lock_version' => $artifact->lock_version + 1,
                ]);
                $this->appendRetirementAudit(
                    $artifact,
                    'APPEARANCE_RETIRED',
                    'purge_intent',
                    $stagedPath,
                );

                return 1;
            }, 3);
        }

        return $changed;
    }

    private function eligibleForRetirement(
        PdfSignatureAppearanceArtifact $artifact,
        bool $allowIntent = false,
    ): bool {
        return ($allowIntent || $artifact->retirement_state === 'none')
            && $artifact->deleted_at === null
            && $artifact->retention_until !== null
            && $artifact->retention_until->lte(now())
            && ! $this->hasHoldOrReference($artifact);
    }

    private function hasHoldOrReference(PdfSignatureAppearanceArtifact $artifact): bool
    {
        if ($artifact->evidence_hold_state !== 'none' || (int) $artifact->evidence_hold_mask !== 0
            || ($artifact->legal_hold_until !== null && $artifact->legal_hold_until->gt(now()))) {
            return true;
        }
        $hasChallenge = DB::table('pdf_signing_challenges')
            ->where('appearance_artifact_id', $artifact->id)
            ->whereNull('consumed_at')
            ->whereNull('cancelled_at')
            ->where('expires_at', '>', now())
            ->exists();
        if ($hasChallenge) {
            return true;
        }

        return $artifact->claimed_by_operation_id !== null
            && DB::table('pdf_signing_operations')
                ->where('id', $artifact->claimed_by_operation_id)
                ->whereIn('state', self::NON_TERMINAL_OPERATION_STATES)
                ->exists();
    }

    private function restoreOrCancel(PdfSignatureAppearanceArtifact $artifact): void
    {
        $fromState = $artifact->retirement_state;
        $stagedAuditPath = $artifact->retirement_staged_path;
        $canonical = $this->absolute((string) $artifact->file_path);
        $stagedPath = $artifact->retirement_staged_path;
        $staged = $stagedPath ? $this->absolute($stagedPath) : null;
        if ($staged !== null && is_file($staged)) {
            if (is_file($canonical)) {
                throw new RuntimeException('Appearance hold restore found duplicate canonical bytes.');
            }
            $this->assertHash($staged, $artifact->canonical_image_sha256);
            $this->atomicMove($staged, $canonical);
        }
        if (! is_file($canonical)) {
            throw new RuntimeException('Appearance hold restore cannot locate canonical bytes.');
        }
        $this->assertHash($canonical, $artifact->canonical_image_sha256);
        $artifact->update([
            'retirement_state' => 'none',
            'retirement_epoch' => $artifact->retirement_epoch + 1,
            'retirement_staged_path' => null,
            'retirement_staged_at' => null,
            'retirement_purge_not_before' => null,
            'lock_version' => $artifact->lock_version + 1,
        ]);
        $this->appendRetirementAudit(
            $artifact,
            'APPEARANCE_RETIRE_RESTORED',
            $fromState,
            $stagedAuditPath,
        );
    }

    private function appendRetirementAudit(
        PdfSignatureAppearanceArtifact $artifact,
        string $event,
        string $fromState,
        ?string $stagedPath = null,
    ): void {
        activity('pdf_appearance_retirement')
            ->performedOn($artifact)
            ->event($event)
            ->withProperties([
                'appearance_uuid' => $artifact->appearance_uuid,
                'retirement_epoch' => (int) $artifact->retirement_epoch,
                'from_state' => $fromState,
                'to_state' => $artifact->retirement_state,
                'canonical_image_sha256' => $artifact->canonical_image_sha256,
                'retirement_staged_path' => $stagedPath ?? $artifact->retirement_staged_path,
                'lock_version' => (int) $artifact->lock_version,
            ])
            ->log($event);
    }

    private function lockArtifactWithScope(int $artifactId): PdfSignatureAppearanceArtifact
    {
        $snapshot = PdfSignatureAppearanceArtifact::query()->findOrFail($artifactId);
        if ($snapshot->claimed_by_operation_id === null) {
            return PdfSignatureAppearanceArtifact::query()->lockForUpdate()->findOrFail($artifactId);
        }

        $operation = DB::table('pdf_signing_operations')
            ->where('id', $snapshot->claimed_by_operation_id)
            ->lockForUpdate()
            ->first();
        if ($operation === null) {
            throw new RuntimeException('Appearance retirement owning operation is missing.');
        }
        DB::table('pdf_java_signing_executions')
            ->where('operation_uuid', $operation->operation_uuid)
            ->lockForUpdate()
            ->get();
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
        $artifact = PdfSignatureAppearanceArtifact::query()->lockForUpdate()->findOrFail($artifactId);
        if ((int) $artifact->claimed_by_operation_id !== (int) $operation->id) {
            throw new RuntimeException('Appearance retirement ownership changed while acquiring scope locks.');
        }

        return $artifact;
    }

    private function absolute(string $relativePath): string
    {
        if (preg_match('#^[a-zA-Z0-9/_-]+\.[a-zA-Z0-9]+$#', $relativePath) !== 1) {
            throw new RuntimeException('Appearance retirement path is invalid.');
        }

        return Storage::disk('pdf')->path($relativePath);
    }

    private function assertHash(string $path, string $expectedHash): void
    {
        if (is_link($path) || ! is_file($path) || ! hash_equals($expectedHash, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Appearance retirement bytes failed integrity verification.');
        }
    }

    private function atomicMove(string $source, string $target): void
    {
        $directory = dirname($target);
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create appearance retirement directory.');
        }
        if (! rename($source, $target)) {
            throw new RuntimeException('Unable to atomically move appearance retirement bytes.');
        }
        $this->syncDirectory(dirname($source));
        $this->syncDirectory($directory);
    }

    private function syncDirectory(string $directory): void
    {
        if (! function_exists('fsync')) {
            return;
        }
        $handle = @fopen($directory, 'r');
        if ($handle !== false) {
            @fsync($handle);
            fclose($handle);
        }
    }
}

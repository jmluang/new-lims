<?php

namespace App\Services\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PdfRevisionService
{
    public function __construct(
        private readonly PdfImmutableFileStore $files,
    ) {}

    /**
     * Materialize a preallocated operation revision inside the caller's
     * transaction. The caller owns rollback cleanup of the returned path.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{revision: PdfFile, stored_path: string}
     */
    public function registerOperationRevision(
        PdfDocument $lockedDocument,
        ?PdfFile $parent,
        string $revisionUuid,
        string $role,
        string $storedPath,
        string $sha256,
        int $size,
        User $actor,
        array $manifest,
    ): PdfFile {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Operation revision registration requires an active transaction.');
        }

        $absolutePath = $this->files->verifiedAbsolutePath($storedPath, $sha256, $size);
        $existing = PdfFile::query()->where('revision_uuid', $revisionUuid)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->document_id !== $lockedDocument->id
                || $existing->parent_pdf_file_id !== $parent?->id
                || $existing->revision_role !== $role
                || $existing->file_path !== $storedPath
                || ! hash_equals($existing->sha256_hash, $sha256)
                || (int) $existing->file_size !== $size) {
                throw new RuntimeException('Operation revision identity conflicts with its immutable ledger.');
            }

            return $existing;
        }
        if (PdfFile::query()->where('file_path', $storedPath)->exists()) {
            throw new RuntimeException('Operation revision path is already registered.');
        }
        $revisionNumber = $lockedDocument->next_revision_number;
        $signed = str_contains($role, 'signature') || $role === 'organization_seal';
        $fullManifest = array_merge($manifest, [
            'revision_uuid' => $revisionUuid,
            'revision_sha256' => $sha256,
            'parent_revision_uuid' => $parent?->revision_uuid,
        ]);
        $revision = PdfFile::query()->create([
            'document_id' => $lockedDocument->id,
            'revision_uuid' => $revisionUuid,
            'parent_pdf_file_id' => $parent?->id,
            'revision_number' => $revisionNumber,
            'revision_role' => $role,
            'revision_created_at' => now(),
            'file_id' => "REV-{$revisionUuid}",
            'file_name' => $lockedDocument->authoritative_report_number.'.pdf',
            'file_path' => $storedPath,
            'sha256_hash' => $sha256,
            'md5_hash' => hash_file('md5', $absolutePath),
            'cover_report_number' => $lockedDocument->authoritative_report_number,
            'file_size' => $size,
            'signed_at' => $signed ? now() : null,
            'created_by' => $actor->name,
            'created_by_id' => $actor->id,
            'metadata' => ['signed' => $signed],
            'revision_manifest' => $fullManifest,
            'revision_manifest_hash' => hash('sha256', CanonicalJson::encode($fullManifest)),
            'integrity_state' => 'ready',
            'disposition' => 'active',
        ]);
        $lockedDocument->update(['next_revision_number' => $revisionNumber + 1]);

        return $revision;
    }
}

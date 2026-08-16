<?php

namespace Tests\Unit\Services\Pdf;

use App\Services\Pdf\PdfImmutableFileStore;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PdfImmutableFileStoreTest extends TestCase
{
    public function test_it_durably_promotes_and_verifies_exact_immutable_bytes(): void
    {
        Storage::fake('pdf');
        $bytes = '%PDF-1.7 immutable bytes';
        $store = app(PdfImmutableFileStore::class);

        $stored = $store->putBytes($bytes, 'revisions/test/document.pdf');

        $this->assertSame(hash('sha256', $bytes), $stored['sha256']);
        $this->assertSame(strlen($bytes), $stored['size']);
        $this->assertSame(
            $stored['absolute_path'],
            $store->verifiedAbsolutePath($stored['path'], $stored['sha256'], $stored['size']),
        );
        $this->assertSame(
            $stored['absolute_path'],
            $store->verifiedAbsolutePathByHash($stored['path'], $stored['sha256']),
        );
        $this->assertSame([], glob(dirname($stored['absolute_path']).'/.document.pdf.tmp-*'));
    }

    public function test_it_rejects_symbolic_links_and_does_not_overwrite_existing_targets(): void
    {
        Storage::fake('pdf');
        $store = app(PdfImmutableFileStore::class);
        $stored = $store->putBytes('first', 'revisions/test/document.pdf');

        try {
            $store->putBytes('second', 'revisions/test/document.pdf');
            $this->fail('Immutable targets must not be overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }
        $this->assertSame('first', file_get_contents($stored['absolute_path']));

        $linkPath = Storage::disk('pdf')->path('revisions/test/link.pdf');
        if (! @symlink($stored['absolute_path'], $linkPath)) {
            $this->markTestSkipped('Symbolic links are unavailable on this filesystem.');
        }
        try {
            $store->verifiedAbsolutePathByHash('revisions/test/link.pdf', $stored['sha256']);
            $this->fail('Immutable verification must reject symbolic links.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('symbolic link', $exception->getMessage());
        } finally {
            @unlink($linkPath);
        }
    }

    public function test_operation_candidate_promotion_is_exact_and_replayable(): void
    {
        Storage::fake('pdf');
        $store = app(PdfImmutableFileStore::class);
        $bytes = '%PDF-1.7 promoted operation bytes';
        $sha256 = hash('sha256', $bytes);
        $size = strlen($bytes);
        $stagingPath = 'workflow/staging/operation/7/candidate.pdf';
        $finalPath = 'workflow/revisions/revision/operation/7/document.pdf';

        $candidate = $store->ensureOperationCandidate(
            $bytes,
            $stagingPath,
            $finalPath,
            $sha256,
            $size,
        );
        $this->assertSame('staging', $candidate['location']);
        $promoted = $store->promoteOperationCandidate(
            $stagingPath,
            $finalPath,
            $sha256,
            $size,
        );
        $this->assertSame('final', $promoted['location']);
        Storage::disk('pdf')->assertMissing($stagingPath);
        Storage::disk('pdf')->assertExists($finalPath);

        $replayed = $store->ensureOperationCandidate(
            $bytes,
            $stagingPath,
            $finalPath,
            $sha256,
            $size,
        );
        $this->assertSame('final', $replayed['location']);
        $this->assertSame($promoted['absolute_path'], $replayed['absolute_path']);
    }

    public function test_operation_candidate_rejects_ambiguous_staging_and_final_copies(): void
    {
        Storage::fake('pdf');
        $store = app(PdfImmutableFileStore::class);
        $bytes = '%PDF-1.7 duplicate operation bytes';
        $sha256 = hash('sha256', $bytes);
        $size = strlen($bytes);
        $stagingPath = 'workflow/staging/operation/8/candidate.pdf';
        $finalPath = 'workflow/revisions/revision/operation/8/document.pdf';
        $store->putBytes($bytes, $stagingPath);
        $store->putBytes($bytes, $finalPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ambiguous staging and final copies');
        $store->ensureOperationCandidate($bytes, $stagingPath, $finalPath, $sha256, $size);
    }

    public function test_operation_orphan_quarantine_is_durable_replayable_and_duplicate_fail_closed(): void
    {
        Storage::fake('pdf');
        $store = app(PdfImmutableFileStore::class);
        $operationUuid = '11111111-1111-1111-1111-111111111111';
        $sourcePath = "workflow/staging/{$operationUuid}/7/candidate.pdf";
        $sourceFingerprint = hash('sha256', $sourcePath);
        $quarantinePath = "workflow/quarantine/orphans/{$operationUuid}/{$sourceFingerprint}/candidate.pdf";
        $bytes = '%PDF-1.7 orphan quarantine bytes';
        $stored = $store->putBytes($bytes, $sourcePath);

        $quarantined = $store->quarantineOperationOrphan(
            $sourcePath,
            $quarantinePath,
            $stored['sha256'],
            $stored['size'],
        );
        Storage::disk('pdf')->assertMissing($sourcePath);
        Storage::disk('pdf')->assertExists($quarantinePath);
        $this->assertSame($quarantined, $store->quarantineOperationOrphan(
            $sourcePath,
            $quarantinePath,
            $stored['sha256'],
            $stored['size'],
        ));

        $store->putBytes($bytes, $sourcePath);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('both source and quarantine');
        $store->quarantineOperationOrphan(
            $sourcePath,
            $quarantinePath,
            $stored['sha256'],
            $stored['size'],
        );
    }

    public function test_prior_epoch_candidate_is_read_from_one_descriptor_and_adopted_into_the_current_fence(): void
    {
        Storage::fake('pdf');
        $store = app(PdfImmutableFileStore::class);
        $operationUuid = '11111111-1111-1111-1111-111111111111';
        $revisionUuid = '22222222-2222-2222-2222-222222222222';
        $oldPath = "workflow/staging/{$operationUuid}/4/candidate.pdf";
        $currentPath = "workflow/staging/{$operationUuid}/5/candidate.pdf";
        $currentFinal = "workflow/revisions/{$revisionUuid}/{$operationUuid}/5/document.pdf";
        $bytes = '%PDF-1.7 prior epoch candidate';
        $stored = $store->putBytes($bytes, $oldPath);

        $fallback = $store->readOperationCandidateFallback(
            $operationUuid,
            $revisionUuid,
            $stored['sha256'],
            $stored['size'],
            1024,
        );
        $this->assertSame($bytes, $fallback['body']);
        $this->assertSame($oldPath, $fallback['path']);
        $this->assertSame('staging', $fallback['kind']);

        $adopted = $store->adoptOperationCandidate(
            $fallback['path'],
            $currentPath,
            $currentFinal,
            $stored['sha256'],
            $stored['size'],
        );
        $this->assertSame('staging', $adopted['location']);
        Storage::disk('pdf')->assertMissing($oldPath);
        Storage::disk('pdf')->assertExists($currentPath);
        $this->assertSame($bytes, Storage::disk('pdf')->get($currentPath));
    }

    public function test_prior_epoch_candidate_recovery_rejects_multiple_exact_copies(): void
    {
        Storage::fake('pdf');
        $store = app(PdfImmutableFileStore::class);
        $operationUuid = '11111111-1111-1111-1111-111111111111';
        $revisionUuid = '22222222-2222-2222-2222-222222222222';
        $bytes = '%PDF-1.7 ambiguous prior candidates';
        $sha256 = hash('sha256', $bytes);
        $size = strlen($bytes);
        $store->putBytes($bytes, "workflow/staging/{$operationUuid}/4/candidate.pdf");
        $store->putBytes($bytes, "workflow/revisions/{$revisionUuid}/{$operationUuid}/3/document.pdf");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fallback is ambiguous');
        $store->readOperationCandidateFallback(
            $operationUuid,
            $revisionUuid,
            $sha256,
            $size,
            1024,
        );
    }
}

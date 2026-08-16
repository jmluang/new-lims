<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class PdfImmutableFileStore
{
    /**
     * @return array{location: 'staging'|'final', path: string, absolute_path: string, sha256: string, size: int}
     */
    public function ensureOperationCandidate(
        string $bytes,
        string $stagingPath,
        string $finalPath,
        string $expectedSha256,
        int $expectedSize,
    ): array {
        if (strlen($bytes) !== $expectedSize || ! hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Operation candidate bytes do not match the frozen Java result.');
        }
        $stagingAbsolute = $this->absolute($stagingPath);
        $finalAbsolute = $this->absolute($finalPath);
        $stagingExists = $this->pathExists($stagingAbsolute);
        $finalExists = $this->pathExists($finalAbsolute);
        if ($stagingExists && $finalExists) {
            throw new RuntimeException('Operation candidate has ambiguous staging and final copies.');
        }
        if ($finalExists) {
            $this->verifyDescriptor($finalAbsolute, $expectedSha256, $expectedSize);

            return $this->candidate('final', $finalPath, $finalAbsolute, $expectedSha256, $expectedSize);
        }
        if ($stagingExists) {
            $this->verifyDescriptor($stagingAbsolute, $expectedSha256, $expectedSize);

            return $this->candidate('staging', $stagingPath, $stagingAbsolute, $expectedSha256, $expectedSize);
        }

        $stored = $this->putBytes($bytes, $stagingPath);
        if (! hash_equals($expectedSha256, $stored['sha256']) || $expectedSize !== $stored['size']) {
            throw new RuntimeException('Durable operation staging bytes failed exact identity verification.');
        }

        return $this->candidate('staging', $stagingPath, $stored['absolute_path'], $expectedSha256, $expectedSize);
    }

    /** @return array{location: 'final', path: string, absolute_path: string, sha256: string, size: int} */
    public function promoteOperationCandidate(
        string $stagingPath,
        string $finalPath,
        string $expectedSha256,
        int $expectedSize,
    ): array {
        $stagingAbsolute = $this->absolute($stagingPath);
        $finalAbsolute = $this->absolute($finalPath);
        $stagingExists = $this->pathExists($stagingAbsolute);
        $finalExists = $this->pathExists($finalAbsolute);
        if ($stagingExists && $finalExists) {
            throw new RuntimeException('Operation promotion found ambiguous staging and final copies.');
        }
        if ($finalExists) {
            $this->verifyDescriptor($finalAbsolute, $expectedSha256, $expectedSize);

            return $this->candidate('final', $finalPath, $finalAbsolute, $expectedSha256, $expectedSize);
        }
        if (! $stagingExists) {
            throw new RuntimeException('Operation promotion cannot locate exact candidate bytes.');
        }
        $this->verifyDescriptor($stagingAbsolute, $expectedSha256, $expectedSize);
        $this->ensureDirectory(dirname($finalAbsolute));
        if (! @rename($stagingAbsolute, $finalAbsolute)) {
            throw new RuntimeException('Unable to atomically promote operation candidate bytes.');
        }
        $this->syncDirectory(dirname($stagingAbsolute));
        $this->syncDirectory(dirname($finalAbsolute));
        $this->verifyDescriptor($finalAbsolute, $expectedSha256, $expectedSize);

        return $this->candidate('final', $finalPath, $finalAbsolute, $expectedSha256, $expectedSize);
    }

    /**
     * @return array{path: string, absolute_path: string, sha256: string, size: int}
     */
    public function copyFromPath(string $sourcePath, string $targetPath): array
    {
        $source = @fopen($sourcePath, 'rb');

        if ($source === false) {
            throw new RuntimeException('Immutable PDF source is not readable.');
        }

        try {
            return $this->writeStream($source, $targetPath);
        } finally {
            fclose($source);
        }
    }

    /**
     * @return array{path: string, absolute_path: string, sha256: string, size: int}
     */
    public function putBytes(string $bytes, string $targetPath): array
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to allocate immutable PDF staging stream.');
        }

        try {
            if (fwrite($stream, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Unable to buffer complete immutable PDF bytes.');
            }
            rewind($stream);

            return $this->writeStream($stream, $targetPath);
        } finally {
            fclose($stream);
        }
    }

    public function verifiedAbsolutePath(string $path, string $expectedSha256, int $expectedSize): string
    {
        $absolutePath = Storage::disk('pdf')->path($path);
        $this->verifyDescriptor($absolutePath, $expectedSha256, $expectedSize);

        return $absolutePath;
    }

    public function verifiedAbsolutePathByHash(string $path, string $expectedSha256): string
    {
        $absolutePath = Storage::disk('pdf')->path($path);
        $this->verifyDescriptor($absolutePath, $expectedSha256, null);

        return $absolutePath;
    }

    /** @return array{path: string, absolute_path: string, sha256: string, size: int} */
    public function inspectImmutableFile(string $path): array
    {
        $absolutePath = $this->absolute($path);
        $descriptor = $this->describeDescriptor($absolutePath);

        return [
            'path' => $path,
            'absolute_path' => $absolutePath,
            'sha256' => $descriptor['sha256'],
            'size' => $descriptor['size'],
        ];
    }

    public function readVerifiedImmutableFile(
        string $path,
        string $expectedSha256,
        int $expectedSize,
        int $maximumSize,
    ): string {
        if ($expectedSize < 0 || $expectedSize > $maximumSize) {
            throw new RuntimeException('Immutable file exceeds its frozen read budget.');
        }

        return $this->readVerifiedDescriptor(
            $this->absolute($path),
            $expectedSha256,
            $expectedSize,
        );
    }

    /** @return array{path: string, absolute_path: string, sha256: string, size: int} */
    public function quarantineOperationOrphan(
        string $sourcePath,
        string $quarantinePath,
        string $expectedSha256,
        int $expectedSize,
    ): array {
        if (! $this->isOperationCandidatePath($sourcePath)
            || preg_match('#^workflow/quarantine/orphans/[0-9a-f-]{36}/[0-9a-f]{64}/(candidate|document)\.pdf$#', $quarantinePath) !== 1) {
            throw new RuntimeException('Operation orphan quarantine path contract is invalid.');
        }
        $sourceAbsolute = $this->absolute($sourcePath);
        $quarantineAbsolute = $this->absolute($quarantinePath);
        $sourceExists = $this->pathExists($sourceAbsolute);
        $quarantineExists = $this->pathExists($quarantineAbsolute);
        if ($sourceExists && $quarantineExists) {
            throw new RuntimeException('Operation orphan exists at both source and quarantine paths.');
        }
        if ($quarantineExists) {
            if (! @chmod($quarantineAbsolute, 0440)) {
                throw new RuntimeException('Unable to restore quarantined operation orphan permissions.');
            }
            $this->syncDirectory(dirname($sourceAbsolute));
            $this->syncDirectory(dirname($quarantineAbsolute));
            $this->verifyDescriptor($quarantineAbsolute, $expectedSha256, $expectedSize);

            return [
                'path' => $quarantinePath,
                'absolute_path' => $quarantineAbsolute,
                'sha256' => $expectedSha256,
                'size' => $expectedSize,
            ];
        }
        if (! $sourceExists) {
            throw new RuntimeException('Operation orphan is missing from both source and quarantine paths.');
        }
        $this->verifyDescriptor($sourceAbsolute, $expectedSha256, $expectedSize);
        $this->ensureDirectory(dirname($quarantineAbsolute));
        if (! @rename($sourceAbsolute, $quarantineAbsolute)) {
            throw new RuntimeException('Unable to atomically quarantine operation orphan.');
        }
        if (! @chmod($quarantineAbsolute, 0440)) {
            throw new RuntimeException('Unable to make quarantined operation orphan read-only.');
        }
        $this->syncDirectory(dirname($sourceAbsolute));
        $this->syncDirectory(dirname($quarantineAbsolute));
        $this->verifyDescriptor($quarantineAbsolute, $expectedSha256, $expectedSize);

        return [
            'path' => $quarantinePath,
            'absolute_path' => $quarantineAbsolute,
            'sha256' => $expectedSha256,
            'size' => $expectedSize,
        ];
    }

    /** @return array{body: string, path: string, kind: 'staging'|'final', sha256: string, size: int} */
    public function readOperationCandidateFallback(
        string $operationUuid,
        string $revisionUuid,
        string $expectedSha256,
        int $expectedSize,
        int $maximumSize,
    ): array {
        if (! Str::isUuid($operationUuid) || ! Str::isUuid($revisionUuid)
            || $expectedSize < 0 || $expectedSize > $maximumSize) {
            throw new RuntimeException('Operation candidate fallback identity is invalid.');
        }
        $patterns = [
            'staging' => "workflow/staging/{$operationUuid}/*/candidate.pdf",
            'final' => "workflow/revisions/{$revisionUuid}/{$operationUuid}/*/document.pdf",
        ];
        $matches = [];
        foreach ($patterns as $kind => $pattern) {
            $absolutePattern = Storage::disk('pdf')->path($pattern);
            foreach (glob($absolutePattern, GLOB_NOSORT) ?: [] as $absolutePath) {
                if (count($matches) >= 100) {
                    throw new RuntimeException('Operation candidate fallback exceeds the ambiguity limit.');
                }
                $path = $this->relativeToPdfDisk($absolutePath);
                if (! $this->isOperationCandidatePath($path)) {
                    throw new RuntimeException('Operation candidate fallback escaped its path contract.');
                }
                try {
                    $body = $this->readVerifiedDescriptor($absolutePath, $expectedSha256, $expectedSize);
                } catch (RuntimeException $exception) {
                    if (is_link($absolutePath)) {
                        throw $exception;
                    }

                    continue;
                }
                $matches[] = [
                    'body' => $body,
                    'path' => $path,
                    'kind' => $kind,
                    'sha256' => $expectedSha256,
                    'size' => $expectedSize,
                ];
            }
        }
        if ($matches === []) {
            throw new RuntimeException('Operation candidate fallback is missing.');
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('Operation candidate fallback is ambiguous.');
        }

        return $matches[0];
    }

    /** @return array{location: 'staging'|'final', path: string, absolute_path: string, sha256: string, size: int} */
    public function adoptOperationCandidate(
        string $sourcePath,
        string $currentStagingPath,
        string $currentFinalPath,
        string $expectedSha256,
        int $expectedSize,
    ): array {
        if (! $this->isOperationCandidatePath($sourcePath)
            || ! $this->isOperationCandidatePath($currentStagingPath)
            || ! $this->isOperationCandidatePath($currentFinalPath)) {
            throw new RuntimeException('Operation candidate adoption path contract is invalid.');
        }
        $sourceAbsolute = $this->absolute($sourcePath);
        $targetPath = str_ends_with($sourcePath, '/candidate.pdf') ? $currentStagingPath : $currentFinalPath;
        $targetAbsolute = $this->absolute($targetPath);
        if ($sourcePath === $targetPath) {
            if (! @chmod($sourceAbsolute, 0440)) {
                throw new RuntimeException('Unable to restore adopted operation candidate permissions.');
            }
            $this->syncDirectory(dirname($sourceAbsolute));
            $this->verifyDescriptor($sourceAbsolute, $expectedSha256, $expectedSize);

            return $this->candidate(
                $targetPath === $currentFinalPath ? 'final' : 'staging',
                $targetPath,
                $targetAbsolute,
                $expectedSha256,
                $expectedSize,
            );
        }
        if ($this->pathExists($targetAbsolute)
            || ($targetPath === $currentStagingPath && $this->pathExists($this->absolute($currentFinalPath)))
            || ($targetPath === $currentFinalPath && $this->pathExists($this->absolute($currentStagingPath)))) {
            throw new RuntimeException('Operation candidate adoption found a competing current-fence copy.');
        }
        $this->verifyDescriptor($sourceAbsolute, $expectedSha256, $expectedSize);
        $this->ensureDirectory(dirname($targetAbsolute));
        if (! @rename($sourceAbsolute, $targetAbsolute)) {
            throw new RuntimeException('Unable to atomically adopt the prior operation candidate.');
        }
        if (! @chmod($targetAbsolute, 0440)) {
            throw new RuntimeException('Unable to make the adopted operation candidate read-only.');
        }
        $this->syncDirectory(dirname($sourceAbsolute));
        $this->syncDirectory(dirname($targetAbsolute));
        $this->verifyDescriptor($targetAbsolute, $expectedSha256, $expectedSize);

        return $this->candidate(
            $targetPath === $currentFinalPath ? 'final' : 'staging',
            $targetPath,
            $targetAbsolute,
            $expectedSha256,
            $expectedSize,
        );
    }

    /**
     * @param  resource  $source
     * @return array{path: string, absolute_path: string, sha256: string, size: int}
     */
    private function writeStream($source, string $targetPath): array
    {
        $disk = Storage::disk('pdf');

        if ($disk->exists($targetPath)) {
            throw new RuntimeException('Immutable file target already exists.');
        }

        $absolutePath = $this->absolute($targetPath);
        $directory = dirname($absolutePath);
        $this->ensureDirectory($directory);

        $temporaryPath = $directory.'/.'.basename($targetPath).'.tmp-'.bin2hex(random_bytes(12));
        $target = @fopen($temporaryPath, 'xb');

        if ($target === false) {
            throw new RuntimeException('Unable to create immutable PDF staging file.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        $promoted = false;

        try {
            try {
                while (! feof($source)) {
                    $chunk = fread($source, 1024 * 1024);

                    if ($chunk === false) {
                        throw new RuntimeException('Unable to read immutable PDF source.');
                    }

                    if ($chunk === '') {
                        continue;
                    }

                    $written = fwrite($target, $chunk);

                    if ($written !== strlen($chunk)) {
                        throw new RuntimeException('Unable to persist complete immutable PDF bytes.');
                    }

                    hash_update($hash, $chunk);
                    $size += $written;
                }

                if (! fflush($target)) {
                    throw new RuntimeException('Unable to flush immutable PDF staging bytes.');
                }

                if (! function_exists('fsync') || ! fsync($target)) {
                    throw new RuntimeException('Unable to fsync immutable PDF staging bytes.');
                }
            } finally {
                fclose($target);
            }

            if (! @rename($temporaryPath, $absolutePath)) {
                throw new RuntimeException('Unable to atomically promote immutable PDF bytes.');
            }
            $promoted = true;

            if (! @chmod($absolutePath, 0440)) {
                throw new RuntimeException('Unable to make immutable PDF bytes read-only.');
            }
            $this->syncDirectory($directory);
        } catch (\Throwable $exception) {
            if (! $promoted) {
                @unlink($temporaryPath);
            }
            throw $exception;
        }

        return [
            'path' => $targetPath,
            'absolute_path' => $absolutePath,
            'sha256' => hash_final($hash),
            'size' => $size,
        ];
    }

    private function verifyDescriptor(string $absolutePath, string $expectedSha256, ?int $expectedSize): void
    {
        $descriptor = $this->describeDescriptor($absolutePath);
        if (($expectedSize !== null && $descriptor['size'] !== $expectedSize)
            || ! hash_equals($expectedSha256, $descriptor['sha256'])) {
            throw new RuntimeException('Immutable file integrity check failed.');
        }
    }

    /** @return array{sha256: string, size: int} */
    private function describeDescriptor(string $absolutePath): array
    {
        if (is_link($absolutePath)) {
            throw new RuntimeException('Immutable file must not be a symbolic link.');
        }
        $stream = @fopen($absolutePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Immutable file bytes are missing or unreadable.');
        }

        try {
            $stat = fstat($stream);
            if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)) {
                throw new RuntimeException('Immutable file is not a regular file.');
            }
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);
            $sha256 = hash_final($hash);
            if ($size === false) {
                throw new RuntimeException('Immutable file integrity check failed.');
            }

            return ['sha256' => $sha256, 'size' => $size];
        } finally {
            fclose($stream);
        }
    }

    private function readVerifiedDescriptor(
        string $absolutePath,
        string $expectedSha256,
        int $expectedSize,
    ): string {
        if (is_link($absolutePath)) {
            throw new RuntimeException('Immutable file must not be a symbolic link.');
        }
        $stream = @fopen($absolutePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Immutable file bytes are missing or unreadable.');
        }
        try {
            $stat = fstat($stream);
            if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)) {
                throw new RuntimeException('Immutable file is not a regular file.');
            }
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);
            if ($size === false || $size !== $expectedSize
                || ! hash_equals($expectedSha256, hash_final($hash))) {
                throw new RuntimeException('Immutable file integrity check failed.');
            }
            rewind($stream);
            $body = stream_get_contents($stream);
            if ($body === false || strlen($body) !== $expectedSize) {
                throw new RuntimeException('Unable to read the verified immutable descriptor.');
            }

            return $body;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array{location: 'staging'|'final', path: string, absolute_path: string, sha256: string, size: int}
     */
    private function candidate(
        string $location,
        string $path,
        string $absolutePath,
        string $sha256,
        int $size,
    ): array {
        return [
            'location' => $location,
            'path' => $path,
            'absolute_path' => $absolutePath,
            'sha256' => $sha256,
            'size' => $size,
        ];
    }

    private function absolute(string $path): string
    {
        if (! preg_match('#^[a-zA-Z0-9/_-]+\.[a-zA-Z0-9]+$#', $path)) {
            throw new RuntimeException('Immutable file target path is invalid.');
        }

        return Storage::disk('pdf')->path($path);
    }

    private function pathExists(string $absolutePath): bool
    {
        return file_exists($absolutePath) || is_link($absolutePath);
    }

    private function isOperationCandidatePath(string $path): bool
    {
        return preg_match('#^workflow/staging/[0-9a-f-]{36}/[0-9]+/candidate\.pdf$#', $path) === 1
            || preg_match('#^workflow/revisions/[0-9a-f-]{36}/[0-9a-f-]{36}/[0-9]+/document\.pdf$#', $path) === 1;
    }

    private function relativeToPdfDisk(string $absolutePath): string
    {
        $root = rtrim(Storage::disk('pdf')->path(''), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($absolutePath, $root)) {
            throw new RuntimeException('Immutable file escaped the PDF storage root.');
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($root)));
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        $missing = [];
        $cursor = $directory;
        while (! is_dir($cursor)) {
            $missing[] = $cursor;
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                throw new RuntimeException('Immutable file directory escaped its storage root.');
            }
            $cursor = $parent;
        }
        if (! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create immutable PDF directory.');
        }
        foreach (array_reverse($missing) as $created) {
            $this->syncDirectory(dirname($created));
            $this->syncDirectory($created);
        }
    }

    private function syncDirectory(string $directory): void
    {
        if (! function_exists('fsync')) {
            throw new RuntimeException('Directory fsync support is required for immutable files.');
        }
        $handle = @fopen($directory, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open immutable file directory for fsync.');
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('Unable to fsync immutable file directory.');
            }
        } finally {
            fclose($handle);
        }
    }
}

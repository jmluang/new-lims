<?php

namespace App\Services\Pdf;

use App\Models\PdfFile;
use App\Models\PdfVerificationLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Hash-based tamper detection, ported from zs-lims's verifyEnhanced flow.
 *
 * The signing desk records SHA-256 + MD5 + byte length for every file it emits.
 * Verification recomputes those digests over the file in hand and looks for a
 * matching ledger row: any edit — even one that preserves the byte count —
 * changes SHA-256 and fails the lookup.
 */
class PdfVerificationService
{
    private const STORAGE_DISK = 'pdf';

    private const VERIFIED_DIR = 'verified';

    /**
     * Digests computed by the browser before upload.
     *
     * @param  array<string, mixed>  $currentDigests
     * @return array<string, mixed> verification result, shaped for the UI
     */
    public function verifyDigests(
        string $fileName,
        int $fileSize,
        array $currentDigests,
        string $source,
        ?User $user = null,
        ?string $savedFilePath = null,
    ): array {
        $this->assertValidDigests($currentDigests);

        $primaryHash = $currentDigests['primary_hash'];
        $md5Hash = $currentDigests['md5_hash'] ?? null;

        $record = PdfFile::query()->where('sha256_hash', $primaryHash)->first();

        if (! $record && filled($md5Hash)) {
            // MD5 fallback surfaces "we know this file but its SHA-256 moved",
            // which the checks below then report as a collision warning.
            $record = PdfFile::query()->where('md5_hash', $md5Hash)->first();
        }

        $result = $record
            ? $this->buildMatchedResult($fileName, $fileSize, $currentDigests, $record, $primaryHash, $md5Hash)
            : $this->buildUnmatchedResult($fileName, $fileSize, $currentDigests);

        $this->log($fileName, $fileSize, $currentDigests, $result, $source, $user, $savedFilePath);

        return $result;
    }

    /**
     * Server-side variant: digests the uploaded bytes itself, so a tampered
     * client cannot pass digests that belong to a different file.
     *
     * @return array<string, mixed>
     */
    public function verifyUploadedFile(UploadedFile $file, string $source, ?User $user = null): array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new InvalidArgumentException('无法读取上传的文件');
        }

        $digests = $this->calculateDigests($path);
        $fileName = $file->getClientOriginalName() ?: 'document.pdf';

        $savedFilePath = $this->shouldStoreUploads($source)
            ? $this->storeVerifiedFile($path, $fileName)
            : null;

        return $this->verifyDigests($fileName, $digests['file_size'], $digests, $source, $user, $savedFilePath);
    }

    /**
     * Mirrors the browser's DigestCalculator so both paths produce comparable
     * payloads. SHA3-256 and CRC32 are recorded but not part of the verdict.
     *
     * @return array<string, mixed>
     */
    public function calculateDigests(string $filePath): array
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            throw new InvalidArgumentException('无法读取文件内容');
        }

        return [
            'file_size' => strlen($contents),
            'calculated_at' => now()->toIso8601String(),
            'digest_version' => '2.0',
            'primary_hash' => hash('sha256', $contents),
            'secondary_hash' => hash('sha3-256', $contents),
            'md5_hash' => hash('md5', $contents),
            'crc32_hash' => hash('crc32b', $contents),
        ];
    }

    /**
     * @param  array<string, mixed>  $currentDigests
     */
    private function assertValidDigests(array $currentDigests): void
    {
        $primaryHash = $currentDigests['primary_hash'] ?? null;

        if (blank($primaryHash)) {
            throw new InvalidArgumentException('缺少主要摘要(SHA256)');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', (string) $primaryHash)) {
            throw new InvalidArgumentException('主要摘要(SHA256)格式无效');
        }

        if ((int) ($currentDigests['file_size'] ?? 0) < 1) {
            throw new InvalidArgumentException('文件大小无效');
        }

        $secondaryHash = $currentDigests['secondary_hash'] ?? null;

        if (filled($secondaryHash) && ! preg_match('/^[a-f0-9]{64}$/', (string) $secondaryHash)) {
            throw new InvalidArgumentException('备选摘要(SHA3-256)格式无效');
        }

        $md5Hash = $currentDigests['md5_hash'] ?? null;

        if (filled($md5Hash) && ! preg_match('/^[a-f0-9]{32}$/', (string) $md5Hash)) {
            throw new InvalidArgumentException('MD5摘要格式无效');
        }
    }

    /**
     * @param  array<string, mixed>  $currentDigests
     * @return array<string, mixed>
     */
    private function buildMatchedResult(
        string $fileName,
        int $fileSize,
        array $currentDigests,
        PdfFile $record,
        string $primaryHash,
        ?string $md5Hash,
    ): array {
        $hashMatch = $record->sha256_hash === $primaryHash;
        $md5Match = filled($record->md5_hash) && $record->md5_hash === $md5Hash;
        $sizeMatch = (int) $record->file_size === $fileSize;

        $failureReasons = [];

        // Ordered checks: report the coarsest mismatch first so the operator
        // gets one actionable reason instead of a wall of failures.
        if (! $sizeMatch) {
            $failureReasons[] = '文件大小不匹配';
        } elseif (! $hashMatch) {
            $failureReasons[] = 'SHA256摘要不匹配';
        } elseif (blank($record->md5_hash)) {
            $failureReasons[] = '数据库中缺少MD5摘要信息，无法完成完整验证';
        } elseif (! $md5Match) {
            $failureReasons[] = 'MD5摘要不匹配';
        }

        $warnings = [];

        if ($md5Match && ! $hashMatch) {
            $warnings[] = 'MD5匹配但SHA256不匹配，可能遭受碰撞攻击！';
        }

        $overallValid = $failureReasons === [];

        return $this->composeResult(
            fileName: $fileName,
            fileSize: $fileSize,
            currentDigests: $currentDigests,
            overallValid: $overallValid,
            securityLevel: match (true) {
                ! $overallValid => 'compromised',
                $hashMatch => 'high',
                $md5Match => 'medium',
                default => 'low',
            },
            failureReasons: $failureReasons,
            digestDetails: [
                'primary_hash' => $hashMatch,
                'md5_hash' => $md5Match,
                'file_size' => $sizeMatch,
            ],
            databaseVerification: [
                'found' => true,
                'hash_match' => $hashMatch,
                'md5_match' => $md5Match,
                'size_match' => $sizeMatch,
                'record' => $this->buildRecordArray($record),
            ],
            warnings: $warnings,
            record: $record,
        );
    }

    /**
     * @param  array<string, mixed>  $currentDigests
     * @return array<string, mixed>
     */
    private function buildUnmatchedResult(string $fileName, int $fileSize, array $currentDigests): array
    {
        return $this->composeResult(
            fileName: $fileName,
            fileSize: $fileSize,
            currentDigests: $currentDigests,
            overallValid: false,
            securityLevel: 'compromised',
            failureReasons: ['未找到数据库记录'],
            digestDetails: [],
            databaseVerification: [
                'found' => false,
                'message' => '未找到匹配的签名记录',
            ],
            warnings: [],
            record: null,
        );
    }

    /**
     * @param  array<string, mixed>  $currentDigests
     * @param  list<string>  $failureReasons
     * @param  array<string, bool>  $digestDetails
     * @param  array<string, mixed>  $databaseVerification
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function composeResult(
        string $fileName,
        int $fileSize,
        array $currentDigests,
        bool $overallValid,
        string $securityLevel,
        array $failureReasons,
        array $digestDetails,
        array $databaseVerification,
        array $warnings,
        ?PdfFile $record,
    ): array {
        return [
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'current_digests' => $currentDigests,
            'verified_at' => now()->toIso8601String(),
            'verification_method' => 'hash_based',
            'overall_valid' => $overallValid,
            'security_level' => $securityLevel,
            'verification_message' => $overallValid
                ? '验证通过'
                : '验证失败: '.implode('，', $failureReasons),
            'cover_report_number' => $record?->cover_report_number,
            'cover_fields' => $this->coverFields($record),
            'verification_details' => [
                'current_digests' => $digestDetails === [] ? null : [
                    'valid' => ! in_array(false, $digestDetails, true),
                    'details' => $digestDetails,
                ],
                'database_verification' => $databaseVerification,
                'warnings' => array_values(array_merge($warnings, $failureReasons)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRecordArray(PdfFile $record): array
    {
        return [
            'id' => $record->id,
            'file_id' => $record->file_id,
            'file_name' => $record->file_name,
            'signed_at' => $record->signed_at?->toIso8601String(),
            'created_by' => $record->created_by,
            'file_size' => $record->file_size,
            'cover_report_number' => $record->cover_report_number,
            'cover_fields' => $this->coverFields($record),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function coverFields(?PdfFile $record): ?array
    {
        $metadata = $record?->metadata;

        return is_array($metadata) && is_array($metadata['cover_fields'] ?? null)
            ? $metadata['cover_fields']
            : null;
    }

    /**
     * @param  array<string, mixed>  $currentDigests
     * @param  array<string, mixed>  $result
     */
    private function log(
        string $fileName,
        int $fileSize,
        array $currentDigests,
        array $result,
        string $source,
        ?User $user,
        ?string $savedFilePath,
    ): void {
        try {
            PdfVerificationLog::query()->create([
                'user_id' => $user?->id,
                'verify_source' => $source,
                'file_name' => Str::limit($fileName, 490, ''),
                'file_size' => $fileSize,
                'primary_hash' => $currentDigests['primary_hash'] ?? null,
                'secondary_hash' => $currentDigests['secondary_hash'] ?? null,
                'md5_hash' => $currentDigests['md5_hash'] ?? null,
                'crc32_hash' => $currentDigests['crc32_hash'] ?? null,
                'overall_valid' => $result['overall_valid'],
                'security_level' => $result['security_level'],
                'verification_message' => $result['verification_message'],
                'verification_data' => $result,
                'ip_address' => request()?->ip(),
                'user_agent' => Str::limit((string) request()?->userAgent(), 990, ''),
                'saved_file_path' => $savedFilePath,
            ]);
        } catch (\Throwable $exception) {
            // A logging failure must never turn a successful verification into
            // an error for the person holding the report.
            Log::error('记录验证日志失败', ['error' => $exception->getMessage(), 'file_name' => $fileName]);
        }
    }

    private function shouldStoreUploads(string $source): bool
    {
        return $source === PdfVerificationLog::SOURCE_ADMIN
            || (bool) config('pdf_service.public_verification.store_uploads');
    }

    private function storeVerifiedFile(string $sourcePath, string $fileName): ?string
    {
        try {
            $relativePath = self::VERIFIED_DIR.'/'.now()->format('Y-m-d').'/'
                .Str::uuid()->toString().'-'.Str::limit(basename($fileName), 80, '');

            $disk = Storage::disk(self::STORAGE_DISK);
            $stream = fopen($sourcePath, 'rb');

            if ($stream === false) {
                return null;
            }

            try {
                $disk->put($relativePath, $stream);
            } finally {
                fclose($stream);
            }

            return $relativePath;
        } catch (\Throwable $exception) {
            Log::error('保存验证文件失败', ['error' => $exception->getMessage(), 'file_name' => $fileName]);

            return null;
        }
    }
}

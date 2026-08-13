<?php

namespace App\Services\Pdf;

use App\Models\DigitalSignature;
use App\Models\HomepageFunctionStamp;
use App\Models\PdfFile;
use App\Models\PerforationStamp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The signing desk pipeline, ported from zs-lims's PdfProcessingService.
 *
 * 1. optional 光度数据 masking (off by default)
 * 2. stamping + PKCS#7 signing through the Java service
 * 3. persist the signed bytes and record their digests
 *
 * The declaration page (证书模板) is merged in the browser before upload, so by
 * the time a file reaches here the bytes are final apart from the seals.
 */
class PdfSigningService
{
    private const STORAGE_DISK = 'pdf';

    private const SIGNED_DIR = 'signed';

    public function __construct(
        private readonly PdfRendererClient $pdfRendererClient,
        private readonly PhotometricContentRemover $photometricContentRemover,
    ) {}

    /**
     * @param  array{
     *     file_number: string,
     *     operator_name: string,
     *     operator_id?: int|null,
     *     original_name?: string|null,
     *     certificate_id?: int|null,
     *     digital_signature_id?: int|null,
     *     perforation_stamp_id?: int|null,
     *     function_stamp_ids?: list<int>,
     *     remove_photometric_content?: bool,
     * }  $config
     * @return array{path: string, pdf_file: PdfFile, metadata: array<string, mixed>}
     */
    public function handle(string $sourcePath, array $config): array
    {
        $workingDir = storage_path('app/private/pdf/working/'.Str::uuid());
        $this->ensureDirectory($workingDir);

        try {
            $currentPath = $workingDir.'/input.pdf';

            if (! copy($sourcePath, $currentPath)) {
                throw new RuntimeException("无法复制文件到工作目录: {$sourcePath}");
            }

            $removePhotometric = (bool) ($config['remove_photometric_content'] ?? false);

            if ($removePhotometric) {
                // Guarded rather than silently skipped: an operator who ticked
                // the box must not receive an unmasked report believing it was
                // processed.
                $currentPath = $this->photometricContentRemover->remove($currentPath, $workingDir);
            }

            $digitalSignature = $this->resolveDigitalSignature($config['digital_signature_id'] ?? null);
            $perforationStamp = $this->resolvePerforationStamp($config['perforation_stamp_id'] ?? null);
            $functionStamps = $this->resolveFunctionStamps($config['function_stamp_ids'] ?? []);

            $signed = $this->applySeals($currentPath, $digitalSignature, $perforationStamp, $functionStamps, $workingDir);
            $currentPath = $signed['path'];

            $storedPath = $this->store($currentPath);
            $absolutePath = Storage::disk(self::STORAGE_DISK)->path($storedPath);

            $pdfFile = $this->record($absolutePath, $storedPath, $config, $signed['cover_fields'], [
                'digital_signature' => $digitalSignature,
                'perforation_stamp' => $perforationStamp,
                'function_stamps' => $functionStamps,
                'signed' => $signed['signed'],
                'remove_photometric_content' => $removePhotometric,
            ]);

            return [
                'path' => $absolutePath,
                'pdf_file' => $pdfFile,
                'metadata' => [
                    'sha256_hash' => $pdfFile->sha256_hash,
                    'md5_hash' => $pdfFile->md5_hash,
                    'file_size' => $pdfFile->file_size,
                    'cover_report_number' => $pdfFile->cover_report_number,
                    'cover_fields' => $pdfFile->metadata['cover_fields'] ?? null,
                ],
            ];
        } finally {
            $this->deleteDirectory($workingDir);
        }
    }

    /**
     * @param  Collection<int, HomepageFunctionStamp>  $functionStamps
     * @return array{path: string, cover_fields: array<string, mixed>|null, signed: bool}
     */
    private function applySeals(
        string $pdfPath,
        ?DigitalSignature $digitalSignature,
        ?PerforationStamp $perforationStamp,
        Collection $functionStamps,
        string $workingDir,
    ): array {
        // Nothing to stamp and nothing to sign: the Java round trip would only
        // rewrite the bytes, so leave the upload untouched.
        if (! $digitalSignature && ! $perforationStamp && $functionStamps->isEmpty()) {
            Log::info('PDF 签章跳过：未选择任何签章选项');

            return ['path' => $pdfPath, 'cover_fields' => null, 'signed' => false];
        }

        $signing = config('pdf_service.signing');

        $fields = [
            // custom keeps every option on one code path inside the Java service.
            'mode' => 'custom',
            'hash_algo' => $signing['hash_algo'],
            'options[group_size]' => $signing['group_size'],
            'options[stamp_total_height_mm]' => $signing['stamp_total_height_mm'],
            'options[signature_size_mm]' => $signing['signature_size_mm'],
            'options[signature_margin_mm]' => $signing['signature_margin_mm'],
        ];

        if ($signing['tsa_enabled']) {
            $fields['tsa_enabled'] = true;

            if (filled($signing['tsa_url'])) {
                $fields['tsa_url'] = $signing['tsa_url'];
            }
        }

        $files = [];

        if ($digitalSignature) {
            // No signing_key_id: the Java service loads its PKCS#12 material from
            // DEFAULT_PFX_PATH and ignores any key id it is handed.
            $fields['signature_contact'] = $digitalSignature->signature_contact;
            $fields['signature_location'] = $digitalSignature->signature_location;
            $fields['signature_reason'] = $digitalSignature->signature_reason;

            $appearance = $this->imagePath($digitalSignature->appearance_image_path);

            if ($appearance === null) {
                throw new RuntimeException('首页签章图片不存在');
            }

            $files['signature_appearance_image'] = $appearance;
        }

        if ($perforationStamp) {
            $perforationImage = $this->imagePath($perforationStamp->appearance_image_path);

            if ($perforationImage === null) {
                throw new RuntimeException('骑缝章图片不存在');
            }

            $files['perforation_image'] = $perforationImage;
        }

        // Ordering matters: the Java service draws function_stamp_0 leftmost.
        $functionStampIndex = 0;

        foreach ($functionStamps as $functionStamp) {
            $path = $this->imagePath($functionStamp->image_path);

            if ($path === null) {
                Log::warning('功能章图片不存在', [
                    'stamp_id' => $functionStamp->id,
                    'image_path' => $functionStamp->image_path,
                ]);

                continue;
            }

            $files['function_stamp_'.$functionStampIndex] = $path;
            $functionStampIndex++;
        }

        $fields['function_stamp_count'] = $functionStampIndex;

        Log::info('发起 PDF 签章请求', [
            'has_signature' => $digitalSignature !== null,
            'has_perforation' => $perforationStamp !== null,
            'function_stamp_count' => $functionStampIndex,
        ]);

        $result = $this->pdfRendererClient->processPdf($pdfPath, $fields, $files);

        // The client writes the response to its own scratch path; move it under
        // this job's working directory so the finally-block cleans it up.
        $signedPath = $workingDir.'/signed.pdf';

        if (! @rename($result['pdf_path'], $signedPath)) {
            if (! copy($result['pdf_path'], $signedPath)) {
                throw new RuntimeException('无法保存签章结果文件');
            }

            @unlink($result['pdf_path']);
        }

        return [
            'path' => $signedPath,
            'cover_fields' => $result['cover_fields'] ?? null,
            'signed' => true,
        ];
    }

    private function store(string $sourcePath): string
    {
        $relativePath = self::SIGNED_DIR.'/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.pdf';
        $disk = Storage::disk(self::STORAGE_DISK);
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('无法读取签章结果文件');
        }

        try {
            $disk->put($relativePath, $stream);
        } finally {
            fclose($stream);
        }

        return $relativePath;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $coverFields
     * @param  array<string, mixed>  $context
     */
    private function record(string $absolutePath, string $storedPath, array $config, ?array $coverFields, array $context): PdfFile
    {
        $sha256 = hash_file('sha256', $absolutePath);
        $md5 = hash_file('md5', $absolutePath);
        $fileSize = filesize($absolutePath);

        $formattedCoverFields = $this->formatCoverFields($coverFields);

        /** @var Collection<int, HomepageFunctionStamp> $functionStamps */
        $functionStamps = $context['function_stamps'];

        // Names are snapshotted alongside the ids: a seal can be deleted later,
        // and the ledger still has to explain what was applied to this file.
        $metadata = [
            'processing_flow' => 'new-lims-v1',
            'signed' => $context['signed'],
            'certificate_id' => $config['certificate_id'] ?? null,
            'certificate_name' => $config['certificate_name'] ?? null,
            'digital_signature_id' => $context['digital_signature']?->id,
            'digital_signature_name' => $context['digital_signature']?->name,
            'perforation_stamp_id' => $context['perforation_stamp']?->id,
            'perforation_stamp_name' => $context['perforation_stamp']?->name,
            'function_stamp_ids' => $functionStamps->pluck('id')->all(),
            'function_stamp_names' => $functionStamps->pluck('name')->all(),
            'cover_fields' => $formattedCoverFields,
            'photometric_content_removal' => [
                'requested' => $context['remove_photometric_content'],
                'performed' => $context['remove_photometric_content'],
                'status' => $context['remove_photometric_content'] ? 'completed' : 'not_requested',
                'method' => 'text_parsing_and_position_calculation',
            ],
        ];

        return PdfFile::query()->create([
            'file_id' => $config['file_number'],
            'file_name' => $config['original_name'] ?? $config['file_number'],
            'file_path' => $storedPath,
            'sha256_hash' => $sha256,
            'md5_hash' => $md5,
            'cover_report_number' => $formattedCoverFields['report_number'] ?? null,
            'file_size' => $fileSize,
            'signed_at' => now(),
            'created_by' => $config['operator_name'],
            'created_by_id' => $config['operator_id'] ?? null,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $coverFields
     * @return array<string, mixed>
     */
    private function formatCoverFields(?array $coverFields): array
    {
        if ($coverFields === null) {
            return [
                'extraction_status' => 'not_available',
                'source' => 'java_pdf_service',
            ];
        }

        $normalized = [
            'report_number' => $coverFields['report_number'] ?? null,
            'product_name' => $coverFields['product_name'] ?? null,
            'model_specification' => $coverFields['model_specification'] ?? null,
            'entrust_company' => $coverFields['entrust_company'] ?? null,
            'test_items' => $coverFields['test_items'] ?? null,
            'report_date' => $coverFields['report_date'] ?? null,
        ];

        $filled = collect($normalized)->filter(fn (mixed $value): bool => filled($value))->count();

        $normalized['extraction_status'] = match (true) {
            $filled === 0 => 'failed',
            $filled === count($normalized) => 'success',
            default => 'partial',
        };
        $normalized['source'] = $coverFields['source'] ?? 'java_pdfbox';

        return $normalized;
    }

    private function resolveDigitalSignature(?int $id): ?DigitalSignature
    {
        if ($id === null) {
            return null;
        }

        $signature = DigitalSignature::query()->active()->find($id);

        if (! $signature) {
            throw new RuntimeException('指定的首页签章无效或未启用');
        }

        return $signature;
    }

    private function resolvePerforationStamp(?int $id): ?PerforationStamp
    {
        if ($id === null) {
            return null;
        }

        $stamp = PerforationStamp::query()->active()->find($id);

        if (! $stamp) {
            throw new RuntimeException('指定的骑缝章无效或未启用');
        }

        return $stamp;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, HomepageFunctionStamp>
     */
    private function resolveFunctionStamps(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $stamps = HomepageFunctionStamp::query()
            ->active()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        // Preserve the caller's ordering — it is the left-to-right draw order.
        return collect($ids)
            ->map(fn (int $id): ?HomepageFunctionStamp => $stamps->get($id))
            ->filter()
            ->values();
    }

    private function imagePath(?string $relativePath): ?string
    {
        if (blank($relativePath)) {
            return null;
        }

        $disk = Storage::disk(self::STORAGE_DISK);

        return $disk->exists($relativePath) ? $disk->path($relativePath) : null;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("无法创建工作目录: {$directory}");
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}

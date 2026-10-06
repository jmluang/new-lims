<?php

namespace App\Services\Pdf;

use App\Jobs\SyncPdfToYanzhenjia;
use App\Models\PdfFile;
use App\Models\PdfYanzhenjiaSync;
use App\Models\PdfYanzhenjiaSetting;
use Illuminate\Support\Facades\DB;

final class PdfYanzhenjiaSyncDispatcher
{
    public const API_LEGACY = 'legacy';

    public const API_V1 = 'v1';

    public function enqueue(PdfFile $file): void
    {
        $setting = PdfYanzhenjiaSetting::query()->find(PdfYanzhenjiaSetting::SINGLETON_ID);

        if ($setting !== null) {
            if (! $setting->enabled || ! $setting->hasCredentials()) {
                return;
            }

            $appid = strtolower((string) $setting->appid);

            PdfYanzhenjiaSync::query()->firstOrCreate(
                ['pdf_file_id' => $file->id, 'api_version' => self::API_V1],
                [
                    'target_appid' => $appid,
                    'request_payload' => $this->buildV1Payload($file, $appid),
                ],
            );

            return;
        }

        // Preserve the pre-v1 environment-based sender for installations that
        // have not saved the new API settings yet. Existing rows are tagged
        // `legacy` by the migration and are never silently replayed to v1.
        if (config('services.yanzhenjia.enabled')) {
            PdfYanzhenjiaSync::query()->firstOrCreate([
                'pdf_file_id' => $file->id,
                'api_version' => self::API_LEGACY,
            ]);
        }
    }

    public function dispatchPending(int $limit = 100): int
    {
        $versions = [];
        $setting = PdfYanzhenjiaSetting::query()->find(PdfYanzhenjiaSetting::SINGLETON_ID);

        if ($setting?->enabled && $setting->hasCredentials()) {
            $versions[] = self::API_V1;
        }

        if (config('services.yanzhenjia.enabled')) {
            $versions[] = self::API_LEGACY;
        }

        if ($versions === []) {
            return 0;
        }

        return DB::transaction(function () use ($limit, $versions): int {
            $syncs = PdfYanzhenjiaSync::query()
                ->whereIn('api_version', $versions)
                ->where(function ($query): void {
                    $query->where('status', 'pending')
                        ->orWhere(function ($query): void {
                            $query->whereIn('status', ['queued', 'running', 'failed'])
                                ->where('updated_at', '<', now()->subMinutes(30));
                        });
                })
                ->where('attempts', '<', 10)
                ->orderBy('id')
                // The company API is limited to 60 requests per minute per
                // company/IP; leave one scheduled batch within that ceiling.
                ->limit(max(1, min($limit, 60)))
                ->lockForUpdate()
                ->get();

            foreach ($syncs as $sync) {
                $sync->update(['status' => 'queued']);
                SyncPdfToYanzhenjia::dispatch($sync->pdf_file_id, $sync->api_version)
                    ->onConnection('database')
                    ->afterCommit();
            }

            return $syncs->count();
        });
    }

    /**
     * @return array<string, int|string>
     */
    private function buildV1Payload(PdfFile $file, string $appid): array
    {
        $metadata = is_array($file->metadata) ? $file->metadata : [];
        $coverFields = is_array($metadata['cover_fields'] ?? null) ? $metadata['cover_fields'] : [];
        $reportNumber = trim((string) $file->cover_report_number);
        $signedAt = $file->signed_at ?? $file->created_at ?? now();
        $fileName = mb_substr($file->signedDownloadName(), 0, 255);
        $payload = [
            'source_file_id' => trim((string) $file->file_id),
            // The app fingerprint keeps this optional cross-company identifier
            // stable while avoiding collisions between separate API accounts.
            'file_no' => 'NEW-LIMS-'.substr(hash('sha256', $appid), 0, 16).'-'.$file->getKey(),
            'file_name' => $fileName,
            'file_date' => $signedAt->toDateString(),
            'sha256' => strtolower((string) $file->sha256_hash),
            'file_size' => (int) $file->file_size,
            'preview_pages' => 0,
        ];

        if ($reportNumber !== '') {
            $payload['report_number'] = mb_substr($reportNumber, 0, 512);
        }

        $md5 = strtolower((string) $file->md5_hash);
        if (preg_match('/\A[a-f0-9]{32}\z/', $md5) === 1) {
            $payload['md5'] = $md5;
        }

        $optionalFields = [
            'report_date' => [$metadata['report_date'] ?? null, 10],
            'product_name' => [$coverFields['product_name'] ?? null, 255],
            'model_specification' => [$coverFields['model_specification'] ?? null, 255],
            'entrusting_unit' => [$coverFields['entrust_company'] ?? null, 255],
            'test_items' => [$coverFields['test_items'] ?? null, 5000],
        ];

        foreach ($optionalFields as $field => [$value, $maxLength]) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            if ($field === 'report_date' && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
                continue;
            }

            if ($field === 'report_date') {
                $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $value) {
                    continue;
                }
            }

            $payload[$field] = mb_substr($value, 0, $maxLength);
        }

        return $payload;
    }
}

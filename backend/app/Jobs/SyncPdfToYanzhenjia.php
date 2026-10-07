<?php

namespace App\Jobs;

use App\Models\PdfFile;
use App\Models\PdfYanzhenjiaSetting;
use App\Models\PdfYanzhenjiaSync;
use App\Services\Pdf\PdfYanzhenjiaSyncDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class SyncPdfToYanzhenjia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $pdfFileId, public readonly ?string $apiVersion = null) {}

    public function handle(): void
    {
        $syncQuery = PdfYanzhenjiaSync::query()->where('pdf_file_id', $this->pdfFileId);
        if ($this->apiVersion !== null) {
            $syncQuery->where('api_version', $this->apiVersion);
        }
        $sync = $syncQuery->orderBy('id')->first();
        if ($sync === null || $sync->status === 'succeeded') {
            return;
        }

        if ($sync->api_version === PdfYanzhenjiaSyncDispatcher::API_V1) {
            $setting = PdfYanzhenjiaSetting::query()->find(PdfYanzhenjiaSetting::SINGLETON_ID);
            if (! $setting?->enabled || ! $setting->hasCredentials()) {
                $sync->update(['status' => 'pending']);

                return;
            }
        }

        $sync->increment('attempts');
        $sync->update(['status' => 'running']);

        try {
            $this->transfer($sync);
        } catch (Throwable $exception) {
            $sync->update([
                'status' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            throw $exception;
        }
    }

    private function transfer(PdfYanzhenjiaSync $sync): void
    {
        if ($sync->api_version === PdfYanzhenjiaSyncDispatcher::API_V1) {
            $this->transferV1($sync);

            return;
        }

        if ($sync->api_version === PdfYanzhenjiaSyncDispatcher::API_LEGACY) {
            $this->transferLegacy($sync);

            return;
        }

        throw new RuntimeException('Unsupported Yanzhenjia API version.');
    }

    private function transferLegacy(PdfYanzhenjiaSync $sync): void
    {
        if (! config('services.yanzhenjia.enabled')) {
            throw new RuntimeException('Yanzhenjia sync is disabled.');
        }

        $secret = (string) config('services.yanzhenjia.secret');
        $baseUrl = rtrim((string) config('services.yanzhenjia.base_url'), '/');
        if (strlen($secret) < 32 || ! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('Yanzhenjia sync requires a shared secret and an HTTPS URL.');
        }

        $file = PdfFile::query()->findOrFail($this->pdfFileId);
        $disk = Storage::disk('pdf');
        if (! $file->file_path || ! $disk->exists($file->file_path)) {
            throw new RuntimeException('The signed PDF is unavailable.');
        }

        $path = $disk->path($file->file_path);
        if ((int) filesize($path) !== (int) $file->file_size
            || ! hash_equals((string) $file->sha256_hash, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('The signed PDF no longer matches its local ledger.');
        }

        $name = trim((string) $file->cover_report_number);
        $fileName = mb_substr($name !== '' ? $name : pathinfo($file->signedDownloadName(), PATHINFO_FILENAME), 0, 251).'.pdf';
        $data = [
            'source_file_id' => (string) $file->file_id,
            'report_number' => $name,
            'file_name' => $fileName,
            'file_date' => ($file->signed_at ?? $file->created_at)->toDateString(),
            'sha256' => strtolower($file->sha256_hash),
            'md5' => strtolower((string) $file->md5_hash),
            'file_size' => (int) $file->file_size,
        ];
        $payload = ['issued_at' => time()] + $data;
        $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $secret);

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The signed PDF could not be opened.');
        }

        try {
            $response = Http::acceptJson()->timeout(90)->withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'X-Lims-Timestamp' => (string) $payload['issued_at'],
                    'X-Lims-Signature' => $signature,
                ])
                ->attach('pdf', $stream, $fileName)
                ->post($baseUrl.'/api/integrations/new-lims/reports', $data);
        } finally {
            fclose($stream);
        }

        if (! $response->successful()
            || $response->json('success') !== true
            || $response->json('username') !== config('services.yanzhenjia.username')
            || ! is_numeric($response->json('id'))) {
            throw new RuntimeException('Yanzhenjia rejected the signed PDF (HTTP '.$response->status().').');
        }

        $this->markSucceeded($sync, (int) $response->json('id'));
    }

    private function transferV1(PdfYanzhenjiaSync $sync): void
    {
        $setting = PdfYanzhenjiaSetting::query()->find(PdfYanzhenjiaSetting::SINGLETON_ID);
        $targetAppid = strtolower((string) $sync->target_appid);
        $appid = strtolower((string) $setting?->appid);
        $secret = (string) ($setting?->secret ?? '');
        $apiUrl = (string) config('services.yanzhenjia.api_url');
        $urlParts = parse_url($apiUrl);

        if (! $setting?->enabled || ! $setting->hasCredentials()
            || $targetAppid === '' || ! hash_equals($targetAppid, $appid)
            || $secret === '' || ! is_array($urlParts)
            || strtolower((string) ($urlParts['scheme'] ?? '')) !== 'https'
            || blank($urlParts['host'] ?? null)
            || isset($urlParts['user']) || isset($urlParts['pass'])
            || ($urlParts['path'] ?? '') !== '/api/v1/files'
            || isset($urlParts['query']) || isset($urlParts['fragment'])) {
            throw new RuntimeException('Yanzhenjia v1 sync is not safely configured.');
        }

        $payload = $sync->request_payload;
        if (! is_array($payload)
            || ! is_string($payload['source_file_id'] ?? null)
            || ! is_string($payload['file_name'] ?? null)
            || ! is_string($payload['file_date'] ?? null)
            || ! is_string($payload['sha256'] ?? null)
            || ! is_int($payload['file_size'] ?? null)
            || $payload['file_size'] <= 0) {
            throw new RuntimeException('The frozen Yanzhenjia request is invalid.');
        }

        $file = PdfFile::query()->findOrFail($this->pdfFileId);
        $disk = Storage::disk('pdf');
        if (! $file->file_path || ! $disk->exists($file->file_path)) {
            throw new RuntimeException('The signed PDF is unavailable.');
        }

        $path = $disk->path($file->file_path);
        $actualSize = filesize($path);
        $actualSha256 = hash_file('sha256', $path);
        $expectedSha256 = strtolower($payload['sha256']);
        if ($actualSize === false || (int) $actualSize !== $payload['file_size']
            || (int) $actualSize !== (int) $file->file_size
            || ! is_string($actualSha256)
            || ! hash_equals($expectedSha256, strtolower($actualSha256))
            || ! hash_equals(strtolower((string) $file->sha256_hash), strtolower($actualSha256))) {
            throw new RuntimeException('The signed PDF no longer matches its frozen Yanzhenjia request.');
        }

        if (isset($payload['md5'])) {
            $actualMd5 = hash_file('md5', $path);
            if (! is_string($actualMd5) || ! hash_equals(strtolower((string) $payload['md5']), strtolower($actualMd5))) {
                throw new RuntimeException('The signed PDF no longer matches its frozen Yanzhenjia request.');
            }
        }

        $response = Http::asJson()
            ->acceptJson()
            ->withBasicAuth($targetAppid, $secret)
            ->withHeaders(['Accept-Language' => 'zh-CN'])
            ->connectTimeout(10)
            ->timeout(90)
            ->withOptions(['allow_redirects' => false])
            ->post($apiUrl, $payload);

        if (! $response->successful()
            || $response->json('success') !== true
            || $response->json('source_file_id') !== $payload['source_file_id']
            || $response->json('hash_verified') !== false
            || ! is_numeric($response->json('id'))) {
            throw new RuntimeException('Yanzhenjia v1 rejected the file registration (HTTP '.$response->status().').');
        }

        $this->markSucceeded($sync, (int) $response->json('id'));
    }

    private function markSucceeded(PdfYanzhenjiaSync $sync, int $remoteId): void
    {
        $sync->update([
            'status' => 'succeeded',
            'remote_file_id' => $remoteId,
            'last_error' => null,
            'synced_at' => now(),
        ]);
    }
}

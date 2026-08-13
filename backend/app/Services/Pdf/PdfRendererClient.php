<?php

namespace App\Services\Pdf;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;
use RuntimeException;

class PdfRendererClient
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'base_uri' => rtrim((string) config('pdf_service.base_url'), '/').'/',
            'timeout' => (float) config('pdf_service.timeout'),
            'http_errors' => false,
        ]);
    }

    public function health(): bool
    {
        $this->ensureEnabled();

        $response = $this->request('GET', 'api/pdf/health');

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * Stamp and/or sign a PDF.
     *
     * @param  array<string, mixed>  $fields  scalar form fields, null values are skipped
     * @param  array<string, string>  $files  extra uploads as field name => absolute path
     * @return array<string, mixed>
     */
    public function processPdf(string $pdfPath, array $fields = [], array $files = []): array
    {
        $this->ensureEnabled();
        $resources = [];
        $multipart = [[
            'name' => 'pdf',
            'contents' => $this->openReadableFile($pdfPath, $resources),
            'filename' => basename($pdfPath),
        ]];

        try {
            foreach ($files as $name => $path) {
                $multipart[] = [
                    'name' => $name,
                    'contents' => $this->openReadableFile($path, $resources),
                    'filename' => basename($path),
                ];
            }

            foreach ($fields as $name => $value) {
                if ($value === null) {
                    continue;
                }

                $multipart[] = [
                    'name' => $name,
                    'contents' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                ];
            }

            $response = $this->request('POST', 'api/pdf/process', ['multipart' => $multipart]);
        } finally {
            $this->closeResources($resources);
        }

        $payload = $this->jsonPayload($response['body']);
        $pdfBase64 = $payload['pdf_base64'] ?? null;

        if (! is_string($pdfBase64) || $pdfBase64 === '') {
            throw new RuntimeException('PDF service response did not include pdf_base64.');
        }

        $pdfBytes = base64_decode($pdfBase64, true);

        if ($pdfBytes === false) {
            throw new RuntimeException('PDF service returned invalid pdf_base64.');
        }

        $outputPath = storage_path('app/private/pdf-renderer/'.Str::uuid().'.pdf');
        $this->ensureDirectory(dirname($outputPath));
        file_put_contents($outputPath, $pdfBytes);

        return [
            'pdf_path' => $outputPath,
            'cover_fields' => $payload['cover_fields'] ?? null,
            'response' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function extractCover(string $pdfPath): array
    {
        $this->ensureEnabled();
        $resources = [];

        try {
            $response = $this->request('POST', 'api/pdf/extract-cover', [
                'multipart' => [[
                    'name' => 'pdf',
                    'contents' => $this->openReadableFile($pdfPath, $resources),
                    'filename' => basename($pdfPath),
                ]],
            ]);
        } finally {
            $this->closeResources($resources);
        }

        return $this->jsonPayload($response['body']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function renderEntrustOrder(array $payload): string
    {
        return $this->renderPdfBytes('api/pdf/entrust-order', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function renderContract(array $payload): string
    {
        return $this->renderPdfBytes('api/pdf/contract', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPdfBytes(string $uri, array $payload): string
    {
        $this->ensureEnabled();
        $response = $this->request('POST', $uri, ['json' => $payload]);

        return $response['body'];
    }

    private function ensureEnabled(): void
    {
        if (! config('pdf_service.enabled')) {
            throw new RuntimeException('PDF service is disabled.');
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->client->request($method, $uri, $options);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('PDF service request failed: '.$exception->getMessage(), previous: $exception);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("PDF service returned HTTP {$status}.");
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(string $body): array
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            throw new RuntimeException('PDF service returned invalid JSON.');
        }

        return $payload;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }

    /**
     * @param  array<int, resource>  $resources
     * @return resource
     */
    private function openReadableFile(string $path, array &$resources)
    {
        $resource = @fopen($path, 'rb');

        if ($resource === false) {
            throw new RuntimeException("PDF service file is not readable: {$path}");
        }

        $resources[] = $resource;

        return $resource;
    }

    /**
     * @param  array<int, resource>  $resources
     */
    private function closeResources(array $resources): void
    {
        foreach ($resources as $resource) {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }
}

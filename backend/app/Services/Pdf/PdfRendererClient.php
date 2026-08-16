<?php

namespace App\Services\Pdf;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\MultipartStream;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class PdfRendererClient
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        if ($client !== null) {
            $this->client = $client;

            return;
        }
        $baseUrl = rtrim((string) config('pdf_service.base_url'), '/');
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('PDF service base URL must resolve through the local loopback boundary.');
        }
        $this->client = new Client([
            'base_uri' => $baseUrl.'/',
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
     * Raw readiness payload from the signing service, for operator diagnostics.
     *
     * @return array<string, mixed>
     */
    public function healthReport(): array
    {
        $this->ensureEnabled();

        try {
            $response = $this->request('GET', 'api/pdf/health');
        } catch (PdfRendererHttpException $exception) {
            return $this->jsonPayload($exception->responseBody);
        }

        return $this->jsonPayload($response['body']);
    }

    /**
     * Decoded length of the configured active HMAC secret. Throws the same
     * actionable error the signing calls would hit, without exposing the secret.
     */
    public function activeHmacSecretBytes(): int
    {
        return strlen($this->activeHmacSecret((string) config('pdf_service.hmac.active_key_id')));
    }

    /**
     * Probe whether this service and the signing service agree on the HMAC key.
     *
     * Sends a signed request that the signing service is expected to reject on
     * business grounds. Reaching that rejection proves the HMAC layer accepted
     * the signature; a PDF_HMAC_* rejection proves it did not.
     *
     * @return array{agreed: bool, blocked: bool, detail: string}
     */
    public function hmacHandshake(): array
    {
        $this->ensureEnabled();
        $probeUuid = (string) Str::uuid();
        $accepted = ['agreed' => true, 'blocked' => false, 'detail' => 'signing service accepted the signature'];

        try {
            $this->request('GET', "internal/pdf/signatures/executions/{$probeUuid}");
        } catch (PdfRendererHttpException $exception) {
            if (preg_match('/PDF_HMAC_[A-Z_]+/', $exception->responseBody, $matches) !== 1) {
                return $accepted;
            }

            // The nonce store is a transport dependency, not a shared secret, so a
            // store outage says nothing about whether the two sides agree on the
            // key. Reporting it as a mismatch would send an operator looking for
            // the wrong problem.
            if ($matches[0] === 'PDF_HMAC_NONCE_STORE_UNAVAILABLE') {
                return [
                    'agreed' => false,
                    'blocked' => true,
                    'detail' => 'cannot tell: the signing service nonce store is unavailable',
                ];
            }

            return ['agreed' => false, 'blocked' => false, 'detail' => $matches[0]];
        }

        return $accepted;
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
            'headers' => ['Content-Type' => 'application/pdf'],
        ]];

        try {
            foreach ($files as $name => $path) {
                $multipart[] = [
                    'name' => $name,
                    'contents' => $this->openReadableFile($path, $resources),
                    'filename' => basename($path),
                    'headers' => ['Content-Type' => $this->fileContentType($path)],
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
     * @return array<string, mixed>
     */
    public function inspectSignaturePdf(string $pdfPath): array
    {
        return $this->signatureMultipartJson('internal/pdf/signatures/inspect', $pdfPath);
    }

    public function finalizeUnsignedPdf(string $pdfPath): string
    {
        return $this->signatureMultipart('internal/pdf/signatures/finalize-unsigned', $pdfPath)['body'];
    }

    /**
     * @param  list<array<string, mixed>>  $fieldPlan
     */
    public function prepareSignatureFields(string $pdfPath, array $fieldPlan): string
    {
        return $this->signatureMultipart('internal/pdf/signatures/prepare', $pdfPath, [[
            'name' => 'field_plan',
            'contents' => json_encode($fieldPlan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'headers' => ['Content-Type' => 'application/json'],
        ]])['body'];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifySignaturePdf(string $pdfPath): array
    {
        return $this->signatureMultipartJson('internal/pdf/signatures/verify', $pdfPath);
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectSignatureBytes(string $pdfBytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lims-pdf-inspect-');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate PDF inspection file.');
        }

        try {
            if (file_put_contents($path, $pdfBytes, LOCK_EX) !== strlen($pdfBytes)) {
                throw new RuntimeException('Unable to write complete PDF inspection bytes.');
            }

            return $this->inspectSignaturePdf($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string, mixed> */
    public function verifySignatureBytes(string $pdfBytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lims-pdf-verify-');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate PDF verification file.');
        }

        try {
            if (file_put_contents($path, $pdfBytes, LOCK_EX) !== strlen($pdfBytes)) {
                throw new RuntimeException('Unable to write complete PDF verification bytes.');
            }

            return $this->verifySignaturePdf($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Submit exactly one execution attempt. This method deliberately has no
     * transport retry; uncertain delivery is resolved through the status API.
     *
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    public function submitSigningExecution(
        string $pdfPath,
        string $appearancePath,
        array $operation,
        array $command,
    ): array {
        $this->ensureEnabled();
        $resources = [];
        $metadataHeaders = $this->executionMetadataHeaders($operation);

        try {
            $response = $this->signatureMultipart(
                'internal/pdf/signatures/sign-existing-field',
                $pdfPath,
                [[
                    'name' => 'appearance',
                    'contents' => $this->openReadableFile($appearancePath, $resources),
                    'filename' => basename($appearancePath),
                    'headers' => ['Content-Type' => 'image/png'],
                ], [
                    'name' => 'operation',
                    'contents' => CanonicalJson::encode($operation),
                    'headers' => ['Content-Type' => 'application/json'],
                ], [
                    'name' => 'command',
                    'contents' => CanonicalJson::encode($command),
                    'headers' => ['Content-Type' => 'application/json'],
                ]],
                $metadataHeaders,
            );
        } finally {
            $this->closeResources($resources);
        }

        return $this->jsonPayload($response['body']);
    }

    /** @return array<string, mixed> */
    public function signingExecutionStatus(string $operationUuid, array $metadata): array
    {
        $this->ensureEnabled();
        $response = $this->request(
            'GET',
            "internal/pdf/signatures/executions/{$operationUuid}",
            ['headers' => $this->executionMetadataHeaders($metadata)],
        );

        return $this->jsonPayload($response['body']);
    }

    /** @return array{body: string, sha256: string, size: int} */
    public function signingExecutionResult(string $operationUuid, array $metadata): array
    {
        $this->ensureEnabled();
        $response = $this->request(
            'GET',
            "internal/pdf/signatures/executions/{$operationUuid}/result",
            ['headers' => $this->executionMetadataHeaders($metadata)],
        );
        $sha256 = strtolower((string) ($response['headers']['X-Pdf-Sha256'][0] ?? ''));
        $body = $response['body'];

        if (! preg_match('/^[0-9a-f]{64}$/', $sha256)
            || ! hash_equals($sha256, hash('sha256', $body))) {
            throw new RuntimeException('PDF execution result response failed SHA-256 verification.');
        }

        return ['body' => $body, 'sha256' => $sha256, 'size' => strlen($body)];
    }

    /** @return array<string, mixed> */
    public function inspectSigningRetirementEvidence(
        string $operationUuid,
        int $retirementEpoch,
        string $retirementPhase,
        string $expectedSha256,
        int $expectedSize,
        array $metadata,
    ): array {
        $this->ensureEnabled();
        $response = $this->request(
            'POST',
            "internal/pdf/signatures/executions/{$operationUuid}/retirement-evidence/inspect",
            [
                'json' => [
                    'retirementEpoch' => $retirementEpoch,
                    'retirementPhase' => $retirementPhase,
                    'expectedSha256' => $expectedSha256,
                    'expectedSize' => $expectedSize,
                ],
                'headers' => $this->executionMetadataHeaders($metadata),
            ],
        );

        return $this->jsonPayload($response['body']);
    }

    /**
     * @return array<string, mixed>
     */
    private function signatureMultipartJson(string $uri, string $pdfPath): array
    {
        return $this->jsonPayload($this->signatureMultipart($uri, $pdfPath)['body']);
    }

    /**
     * @param  list<array<string, mixed>>  $extraParts
     * @return array{status: int, body: string}
     */
    private function signatureMultipart(
        string $uri,
        string $pdfPath,
        array $extraParts = [],
        array $headers = [],
    ): array {
        $this->ensureEnabled();
        $resources = [];

        try {
            $parts = [[
                'name' => 'pdf',
                'contents' => $this->openReadableFile($pdfPath, $resources),
                'filename' => basename($pdfPath),
                'headers' => ['Content-Type' => 'application/pdf'],
            ], ...$extraParts];

            return $this->request('POST', $uri, ['multipart' => $parts, 'headers' => $headers]);
        } finally {
            $this->closeResources($resources);
        }
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
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     */
    private function request(string $method, string $uri, array $options = []): array
    {
        if ($this->shouldSignRequest($method, $uri)) {
            $options = $this->withHmacAuthentication($method, $uri, $options);
        }

        try {
            $response = $this->client->request($method, $uri, $options);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('PDF service request failed: '.$exception->getMessage(), previous: $exception);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw new PdfRendererHttpException($status, $body);
        }

        return ['status' => $status, 'body' => $body, 'headers' => $response->getHeaders()];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, string>
     */
    private function executionMetadataHeaders(array $operation): array
    {
        $headers = [
            'X-Pdf-Operation-Uuid' => (string) ($operation['operationUuid'] ?? $operation['operation_uuid'] ?? ''),
            'X-Pdf-Lease-Epoch' => (string) ($operation['leaseEpoch'] ?? $operation['lease_epoch'] ?? ''),
            'X-Pdf-Operation-Manifest-Sha256' => (string) ($operation['operationInputManifestHash'] ?? $operation['operation_input_manifest_hash'] ?? ''),
            'X-Pdf-Input-Fingerprint' => (string) ($operation['inputFingerprint'] ?? $operation['input_fingerprint'] ?? ''),
            'X-Pdf-Policy-Sha256' => (string) ($operation['policyHash'] ?? $operation['policy_hash'] ?? ''),
            'X-Pdf-Policy-Version-Id' => (string) ($operation['policyVersionId'] ?? $operation['policy_version_id'] ?? ''),
            'X-Pdf-Policy-Version-Uuid' => (string) ($operation['policyVersionUuid'] ?? $operation['policy_version_uuid'] ?? ''),
            'X-Pdf-Config-Bundle-Sha256' => (string) ($operation['configBundleHash'] ?? $operation['config_bundle_hash'] ?? ''),
        ];

        foreach ($headers as $value) {
            if ($value === '') {
                throw new RuntimeException('PDF execution metadata is incomplete.');
            }
        }

        return $headers;
    }

    private function shouldSignRequest(string $method, string $uri): bool
    {
        if (! config('pdf_service.hmac.enabled')) {
            return false;
        }

        return ! (strtoupper($method) === 'GET' && ltrim($uri, '/') === 'api/pdf/health');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withHmacAuthentication(string $method, string $uri, array $options): array
    {
        [$options, $partManifestSha256] = $this->prepareSignedBody($options);
        $keyId = (string) config('pdf_service.hmac.active_key_id');
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId) !== 1) {
            throw new RuntimeException('PDF service HMAC active key id is invalid.');
        }
        $secret = $this->activeHmacSecret($keyId);
        $timestamp = (string) time();
        $nonce = Str::uuid()->toString();
        $correlationUuid = Str::uuid()->toString();
        $version = 'PDF-HMAC-V1';
        $headers = $options['headers'] ?? [];
        $operationUuid = (string) ($headers['X-Pdf-Operation-Uuid'] ?? '-');
        $metadata = [
            'correlation_uuid' => $correlationUuid,
            'operation_uuid' => $operationUuid,
            'version' => 'pdf-request-metadata-v1',
        ];
        if ($operationUuid !== '-') {
            $metadata += [
                'config_bundle_hash' => (string) ($headers['X-Pdf-Config-Bundle-Sha256'] ?? ''),
                'input_fingerprint' => (string) ($headers['X-Pdf-Input-Fingerprint'] ?? ''),
                'lease_epoch' => (int) ($headers['X-Pdf-Lease-Epoch'] ?? -1),
                'operation_input_manifest_hash' => (string) ($headers['X-Pdf-Operation-Manifest-Sha256'] ?? ''),
                'policy_hash' => (string) ($headers['X-Pdf-Policy-Sha256'] ?? ''),
                'signing_policy_version_id' => (int) ($headers['X-Pdf-Policy-Version-Id'] ?? 0),
                'signing_policy_version_uuid' => (string) ($headers['X-Pdf-Policy-Version-Uuid'] ?? ''),
            ];
            foreach ($metadata as $key => $value) {
                if ($value === '' || $value === -1 || $value === 0) {
                    throw new RuntimeException("PDF operation HMAC metadata is incomplete: {$key}");
                }
            }
        }
        $metadataSha256 = hash('sha256', CanonicalJson::encode($metadata));
        $path = $this->canonicalPathAndQuery($uri);
        $canonical = implode("\n", [
            $version,
            $keyId,
            strtoupper($method),
            $path,
            $metadataSha256,
            $partManifestSha256,
            $timestamp,
            $nonce,
            $correlationUuid,
            $operationUuid,
        ]);

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'X-Pdf-Auth-Version' => $version,
            'X-Pdf-Key-Id' => $keyId,
            'X-Pdf-Timestamp' => $timestamp,
            'X-Pdf-Nonce' => $nonce,
            'X-Pdf-Correlation-Id' => $correlationUuid,
            'X-Pdf-Operation-Id' => $operationUuid,
            'X-Pdf-Metadata-Sha256' => $metadataSha256,
            'X-Pdf-Part-Manifest-Sha256' => $partManifestSha256,
            'X-Pdf-Signature' => hash_hmac('sha256', $canonical, $secret),
        ]);

        return $options;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function prepareSignedBody(array $options): array
    {
        if (isset($options['multipart'])) {
            $parts = $options['multipart'];
            $partManifestSha256 = $this->multipartManifestDigest($parts);
            $boundary = 'pdf-'.str_replace('-', '', Str::uuid()->toString());
            unset($options['multipart']);
            $options['body'] = new MultipartStream($parts, $boundary);
            $options['headers'] = array_merge($options['headers'] ?? [], [
                'Content-Type' => 'multipart/form-data; boundary='.$boundary,
            ]);

            return [$options, $partManifestSha256];
        }

        if (array_key_exists('json', $options)) {
            $encoded = json_encode($options['json'], JSON_THROW_ON_ERROR);
            unset($options['json']);
            $options['body'] = $encoded;
            $options['headers'] = array_merge($options['headers'] ?? [], [
                'Content-Type' => 'application/json',
            ]);

            return [$options, $this->bodyManifestDigest($encoded, 'application/json')];
        }

        if (! array_key_exists('body', $options)) {
            return [$options, hash('sha256', CanonicalJson::encode([
                'parts' => [],
                'version' => 'pdf-part-manifest-v1',
            ]))];
        }

        $body = (string) $options['body'];

        return [$options, $this->bodyManifestDigest(
            $body,
            $this->normalizeContentType((string) ($options['headers']['Content-Type'] ?? 'application/octet-stream')),
        )];
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     */
    private function multipartManifestDigest(array $parts): string
    {
        $entries = [];
        $names = [];

        foreach ($parts as $part) {
            $name = (string) ($part['name'] ?? '');
            if ($name === '' || isset($names[$name])) {
                throw new RuntimeException('PDF multipart part names must be non-empty and unique.');
            }
            $names[$name] = true;
            $contents = $part['contents'] ?? '';
            [$size, $sha256] = $this->streamSizeAndDigest($contents);
            $contentType = array_key_exists('filename', $part)
                ? $this->normalizeContentType((string) ($part['headers']['Content-Type'] ?? 'application/octet-stream'))
                : 'text/plain;charset=utf-8';
            $entries[] = [
                'content_type' => $contentType,
                'length' => $size,
                'name' => $name,
                'sha256' => $sha256,
            ];
        }

        usort($entries, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return hash('sha256', CanonicalJson::encode([
            'parts' => $entries,
            'version' => 'pdf-part-manifest-v1',
        ]));
    }

    private function bodyManifestDigest(string $body, string $contentType): string
    {
        return hash('sha256', CanonicalJson::encode([
            'parts' => $body === '' ? [] : [[
                'content_type' => $contentType,
                'length' => strlen($body),
                'name' => 'body',
                'sha256' => hash('sha256', $body),
            ]],
            'version' => 'pdf-part-manifest-v1',
        ]));
    }

    private function normalizeContentType(string $contentType): string
    {
        return strtolower((string) preg_replace('/\s+/', '', trim($contentType)));
    }

    private function canonicalPathAndQuery(string $uri): string
    {
        if (str_contains($uri, '#') || substr_count($uri, '?') > 1 || str_contains($uri, '://')) {
            throw new RuntimeException('PDF service request path is not canonical.');
        }
        $hasQuery = str_contains($uri, '?');
        [$rawPath, $query] = array_pad(explode('?', $uri, 2), 2, '');
        $path = str_starts_with($rawPath, '/') ? $rawPath : '/'.$rawPath;
        if ($path === '' || str_contains($path, '//') || str_contains($path, '\\')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new RuntimeException('PDF service request path is not canonical.');
        }
        $this->validateRfc3986Component($path, path: true, query: false);
        if (! $hasQuery) {
            return $path;
        }
        if ($query === '') {
            return $path.'?';
        }
        $pairs = explode('&', $query);
        $keys = [];
        foreach ($pairs as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key === '' || isset($keys[$key])) {
                throw new RuntimeException('PDF service request query keys must be unique and non-empty.');
            }
            $keys[$key] = true;
            $this->validateRfc3986Component($key, path: false, query: true);
            $this->validateRfc3986Component($value, path: false, query: true);
        }
        $orderedKeys = array_keys($keys);
        $sortedKeys = $orderedKeys;
        sort($sortedKeys, SORT_STRING);
        if ($orderedKeys !== $sortedKeys) {
            throw new RuntimeException('PDF service request query keys must use ASCII sort order.');
        }

        return $path.'?'.$query;
    }

    private function validateRfc3986Component(string $value, bool $path, bool $query): void
    {
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $current = $value[$index];
            if ($current === '%') {
                $hex = substr($value, $index + 1, 2);
                if (strlen($hex) !== 2 || preg_match('/^[0-9A-F]{2}$/', $hex) !== 1) {
                    throw new RuntimeException('PDF service percent encoding must use uppercase hexadecimal.');
                }
                $decoded = chr(hexdec($hex));
                if (preg_match('/^[A-Za-z0-9._~-]$/', $decoded) === 1 || in_array($decoded, ['/', '\\'], true)) {
                    throw new RuntimeException('PDF service percent encoding is not minimal.');
                }
                $index += 2;

                continue;
            }
            $allowed = preg_match("/^[A-Za-z0-9._~!$&'()*+,;=:@-]$/", $current) === 1
                || ($path && $current === '/')
                || ($query && in_array($current, ['/', '?'], true));
            if (! $allowed) {
                throw new RuntimeException('PDF service request target contains a non-RFC3986 character.');
            }
        }
    }

    private function fileContentType(string $path): string
    {
        $detected = mime_content_type($path);

        return $this->normalizeContentType(is_string($detected) ? $detected : 'application/octet-stream');
    }

    /**
     * @param  resource|StreamInterface|string  $contents
     * @return array{0: int, 1: string}
     */
    private function streamSizeAndDigest(mixed $contents): array
    {
        if (is_resource($contents)) {
            $position = ftell($contents);
            if ($position === false || fseek($contents, 0) !== 0) {
                throw new RuntimeException('PDF service request stream must be seekable.');
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $contents);
            $size = ftell($contents);
            fseek($contents, $position);

            return [(int) $size, hash_final($context)];
        }

        if ($contents instanceof StreamInterface) {
            if (! $contents->isSeekable()) {
                throw new RuntimeException('PDF service request stream must be seekable.');
            }
            $position = $contents->tell();
            $contents->rewind();
            $context = hash_init('sha256');
            $size = 0;
            while (! $contents->eof()) {
                $chunk = $contents->read(8192);
                $size += strlen($chunk);
                hash_update($context, $chunk);
            }
            $contents->seek($position);

            return [$size, hash_final($context)];
        }

        $value = (string) $contents;

        return [strlen($value), hash('sha256', $value)];
    }

    /**
     * @param  resource|StreamInterface|string  $contents
     */
    private function streamDigest(mixed $contents): string
    {
        return $this->streamSizeAndDigest($contents)[1];
    }

    private function activeHmacSecret(string $activeKeyId): string
    {
        $configured = (string) config('pdf_service.hmac.keys');

        foreach (array_filter(array_map('trim', explode(',', $configured))) as $entry) {
            [$keyId, $encoded] = array_pad(explode(':', $entry, 2), 2, null);

            if (! is_string($keyId) || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId) !== 1) {
                throw new RuntimeException('PDF service HMAC key ids may only contain letters, digits, dot, underscore, and hyphen.');
            }

            if ($keyId !== $activeKeyId || ! is_string($encoded)) {
                continue;
            }

            $secret = base64_decode($encoded, true);
            if ($secret === false || strlen($secret) < 32) {
                throw new RuntimeException('PDF service HMAC keys must be valid Base64 with at least 32 random bytes.');
            }

            return $secret;
        }

        throw new RuntimeException("PDF service HMAC active key is not configured: {$activeKeyId}");
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

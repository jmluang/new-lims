<?php

namespace Tests\Unit\Services\Pdf;

use App\Services\Pdf\CanonicalJson;
use App\Services\Pdf\PdfRendererClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PdfRendererClientHmacTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    private const ROTATED_SECRET = 'fedcba9876543210fedcba9876543210';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'pdf_service.enabled' => true,
            'pdf_service.hmac.enabled' => true,
            'pdf_service.hmac.active_key_id' => 'primary',
            'pdf_service.hmac.keys' => 'primary:'.base64_encode(self::SECRET),
        ]);
    }

    public function test_json_requests_are_bound_to_the_exact_body(): void
    {
        [$renderer, $history] = $this->rendererWithResponses([
            new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-rendered'),
        ]);

        $this->assertSame('%PDF-rendered', $renderer->renderContract(['report' => 'XDP-1']));

        $request = $history()[0]['request'];
        $body = (string) $request->getBody();
        $this->assertSame('{"report":"XDP-1"}', $body);
        $this->assertRequestSignature($request, $this->bodyManifestDigest($body, 'application/json'));
    }

    public function test_active_key_rotation_selects_the_new_key_from_the_shared_keyring(): void
    {
        config([
            'pdf_service.hmac.active_key_id' => 'rotated',
            'pdf_service.hmac.keys' => implode(',', [
                'primary:'.base64_encode(self::SECRET),
                'rotated:'.base64_encode(self::ROTATED_SECRET),
            ]),
        ]);
        [$renderer, $history] = $this->rendererWithResponses([
            new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-rendered'),
        ]);

        $renderer->renderContract(['report' => 'XDP-rotation']);

        $request = $history()[0]['request'];
        $canonical = implode("\n", [
            $request->getHeaderLine('X-Pdf-Auth-Version'),
            $request->getHeaderLine('X-Pdf-Key-Id'),
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getHeaderLine('X-Pdf-Metadata-Sha256'),
            $request->getHeaderLine('X-Pdf-Part-Manifest-Sha256'),
            $request->getHeaderLine('X-Pdf-Timestamp'),
            $request->getHeaderLine('X-Pdf-Nonce'),
            $request->getHeaderLine('X-Pdf-Correlation-Id'),
            $request->getHeaderLine('X-Pdf-Operation-Id'),
        ]);

        $this->assertSame('rotated', $request->getHeaderLine('X-Pdf-Key-Id'));
        $this->assertSame(
            hash_hmac('sha256', $canonical, self::ROTATED_SECRET),
            $request->getHeaderLine('X-Pdf-Signature'),
        );
    }

    public function test_multipart_requests_use_the_cross_runtime_semantic_manifest(): void
    {
        $directory = storage_path('framework/testing/pdf-hmac-'.Str::uuid());
        mkdir($directory, 0775, true);
        $pdfPath = $directory.'/report.pdf';
        $imagePath = $directory.'/signature.png';
        file_put_contents($pdfPath, '%PDF-1.7');
        file_put_contents($imagePath, 'png-data');

        [$renderer, $history] = $this->rendererWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'pdf_base64' => base64_encode('%PDF-signed'),
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $renderer->processPdf($pdfPath, [
            'mode' => 'custom',
            'signature_reason' => '审核通过',
        ], [
            'signature_appearance_image' => $imagePath,
        ]);

        try {
            $parts = [
                $this->part('pdf', 'application/pdf', '%PDF-1.7'),
                $this->part('signature_appearance_image', 'text/plain', 'png-data'),
                $this->part('mode', 'text/plain;charset=utf-8', 'custom'),
                $this->part('signature_reason', 'text/plain;charset=utf-8', '审核通过'),
            ];
            usort($parts, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
            $partManifestSha256 = hash('sha256', CanonicalJson::encode([
                'parts' => $parts,
                'version' => 'pdf-part-manifest-v1',
            ]));

            $this->assertRequestSignature($history()[0]['request'], $partManifestSha256);
            $this->assertSame('%PDF-signed', file_get_contents($result['pdf_path']));
        } finally {
            @unlink($result['pdf_path']);
            @unlink($pdfPath);
            @unlink($imagePath);
            @rmdir($directory);
        }
    }

    public function test_production_client_rejects_non_loopback_service_urls(): void
    {
        config(['pdf_service.base_url' => 'http://pdf-signer.example.test:8081']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('loopback');

        new PdfRendererClient;
    }

    public function test_php_and_java_share_positive_and_negative_request_to_mac_vectors(): void
    {
        $vectors = json_decode(
            file_get_contents(base_path('../test-fixtures/pdf-hmac-v1-vectors.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $positive = $vectors['positive'];

        $this->assertSame($positive['metadata_jcs'], CanonicalJson::encode($positive['metadata']));
        $this->assertSame($positive['metadata_sha256'], hash('sha256', CanonicalJson::encode($positive['metadata'])));
        $this->assertSame($positive['part_manifest_jcs'], CanonicalJson::encode($positive['part_manifest']));
        $this->assertSame($positive['part_manifest_sha256'], hash('sha256', CanonicalJson::encode($positive['part_manifest'])));

        $fields = array_intersect_key($positive, array_flip([
            'version', 'key_id', 'method', 'path_and_query', 'metadata_sha256',
            'part_manifest_sha256', 'timestamp', 'nonce', 'correlation_uuid', 'operation_uuid',
        ]));
        $canonical = $this->vectorCanonical($fields);
        $this->assertSame($positive['canonical'], $canonical);
        $this->assertSame($positive['signature'], hash_hmac('sha256', $canonical, self::SECRET));

        foreach ($vectors['negative'] as $negative) {
            $tampered = $fields;
            $tampered[$negative['field']] = $negative['value'];
            $this->assertNotSame(
                $positive['signature'],
                hash_hmac('sha256', $this->vectorCanonical($tampered), self::SECRET),
                $negative['name'],
            );
        }
    }

    public function test_request_target_canonicalization_rejects_ambiguous_encodings(): void
    {
        $renderer = new PdfRendererClient(new Client(['base_uri' => 'http://pdf-service.test/']));
        $method = new \ReflectionMethod($renderer, 'canonicalPathAndQuery');

        $this->assertSame(
            '/internal/pdf/signatures/sign-existing-field?attempt=1&mode=append',
            $method->invoke($renderer, 'internal/pdf/signatures/sign-existing-field?attempt=1&mode=append'),
        );

        foreach ([
            'api/pdf/contract?z=1&a=2',
            'api/pdf/contract?a=1&a=2',
            'api/pdf/contract?a=%2f',
            'api/../pdf/contract',
        ] as $target) {
            try {
                $method->invoke($renderer, $target);
                $this->fail("Ambiguous request target was accepted: {$target}");
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_execution_requests_bind_the_complete_frozen_operation_metadata(): void
    {
        [$renderer, $history] = $this->rendererWithResponses([
            new Response(200, ['Content-Type' => 'application/json'], '{"state":"executing"}'),
        ]);
        $operationUuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $operation = [
            'operationUuid' => $operationUuid,
            'leaseEpoch' => 3,
            'operationInputManifestHash' => str_repeat('b', 64),
            'inputFingerprint' => str_repeat('c', 64),
            'policyVersionId' => 7,
            'policyVersionUuid' => '12345678-1234-4234-8234-123456789abc',
            'policyHash' => str_repeat('d', 64),
            'configBundleHash' => str_repeat('e', 64),
        ];

        $this->assertSame('executing', $renderer->signingExecutionStatus($operationUuid, $operation)['state']);

        $this->assertRequestSignature(
            $history()[0]['request'],
            hash('sha256', CanonicalJson::encode([
                'parts' => [],
                'version' => 'pdf-part-manifest-v1',
            ])),
            $operation,
        );
    }

    public function test_canonical_json_rejects_values_outside_the_interoperable_jcs_subset(): void
    {
        foreach ([1.5, PHP_INT_MAX] as $value) {
            try {
                CanonicalJson::encode(['value' => $value]);
                $this->fail('Non-interoperable canonical JSON value was accepted.');
            } catch (\JsonException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    /**
     * @param  list<Response>  $responses
     * @return array{0: PdfRendererClient, 1: callable(): array<int, array<string, mixed>>}
     */
    private function rendererWithResponses(array $responses): array
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $client = new Client([
            'handler' => $stack,
            'base_uri' => 'http://pdf-service.test/',
        ]);

        return [
            new PdfRendererClient($client),
            function () use (&$history): array {
                return $history;
            },
        ];
    }

    /** @param null|array<string, mixed> $operation */
    private function assertRequestSignature(
        $request,
        string $expectedPartManifestSha256,
        ?array $operation = null,
    ): void {
        $version = $request->getHeaderLine('X-Pdf-Auth-Version');
        $keyId = $request->getHeaderLine('X-Pdf-Key-Id');
        $timestamp = $request->getHeaderLine('X-Pdf-Timestamp');
        $nonce = $request->getHeaderLine('X-Pdf-Nonce');
        $correlationUuid = $request->getHeaderLine('X-Pdf-Correlation-Id');
        $operationUuid = $request->getHeaderLine('X-Pdf-Operation-Id');
        $metadataSha256 = $request->getHeaderLine('X-Pdf-Metadata-Sha256');
        $partManifestSha256 = $request->getHeaderLine('X-Pdf-Part-Manifest-Sha256');
        $canonical = implode("\n", [
            $version,
            $keyId,
            $request->getMethod(),
            $request->getUri()->getPath(),
            $metadataSha256,
            $partManifestSha256,
            $timestamp,
            $nonce,
            $correlationUuid,
            $operationUuid,
        ]);

        $this->assertSame('PDF-HMAC-V1', $version);
        $this->assertSame('primary', $keyId);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $correlationUuid);
        $this->assertSame($operation['operationUuid'] ?? '-', $operationUuid);
        $metadata = [
            'correlation_uuid' => $correlationUuid,
            'operation_uuid' => $operationUuid,
            'version' => 'pdf-request-metadata-v1',
        ];
        if ($operation !== null) {
            $metadata += [
                'config_bundle_hash' => $operation['configBundleHash'],
                'input_fingerprint' => $operation['inputFingerprint'],
                'lease_epoch' => $operation['leaseEpoch'],
                'operation_input_manifest_hash' => $operation['operationInputManifestHash'],
                'policy_hash' => $operation['policyHash'],
                'signing_policy_version_id' => $operation['policyVersionId'],
                'signing_policy_version_uuid' => $operation['policyVersionUuid'],
            ];
            $this->assertSame((string) $operation['leaseEpoch'], $request->getHeaderLine('X-Pdf-Lease-Epoch'));
            $this->assertSame($operation['policyVersionUuid'], $request->getHeaderLine('X-Pdf-Policy-Version-Uuid'));
            $this->assertSame($operation['configBundleHash'], $request->getHeaderLine('X-Pdf-Config-Bundle-Sha256'));
        }
        $this->assertSame(hash('sha256', CanonicalJson::encode($metadata)), $metadataSha256);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $partManifestSha256);
        $this->assertSame($expectedPartManifestSha256, $partManifestSha256);
        $this->assertSame(
            hash_hmac('sha256', $canonical, self::SECRET),
            $request->getHeaderLine('X-Pdf-Signature'),
        );
    }

    /** @return array{content_type: string, length: int, name: string, sha256: string} */
    private function part(string $name, string $contentType, string $contents): array
    {
        return [
            'content_type' => $contentType,
            'length' => strlen($contents),
            'name' => $name,
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function bodyManifestDigest(string $body, string $contentType): string
    {
        return hash('sha256', CanonicalJson::encode([
            'parts' => $body === '' ? [] : [$this->part('body', $contentType, $body)],
            'version' => 'pdf-part-manifest-v1',
        ]));
    }

    /** @param array<string, string> $fields */
    private function vectorCanonical(array $fields): string
    {
        return implode("\n", [
            $fields['version'],
            $fields['key_id'],
            strtoupper($fields['method']),
            $fields['path_and_query'],
            strtolower($fields['metadata_sha256']),
            strtolower($fields['part_manifest_sha256']),
            $fields['timestamp'],
            $fields['nonce'],
            $fields['correlation_uuid'],
            $fields['operation_uuid'],
        ]);
    }
}

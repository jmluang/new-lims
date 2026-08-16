<?php

namespace Tests\Unit\Services\Pdf;

use App\Services\Pdf\PdfRendererClient;
use App\Services\Pdf\PdfRuntimeInspector;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class PdfRuntimeInspectorTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

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

    public function test_local_configuration_reports_the_active_secret_length_without_the_secret(): void
    {
        $report = $this->inspector([])->localConfiguration();

        $this->assertTrue($report['ok']);
        $this->assertSame('primary', $report['active_key_id']);
        $this->assertSame(32, $report['secret_bytes']);
        $this->assertNull($report['problem']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($report));
    }

    public function test_local_configuration_explains_how_to_fix_a_missing_key(): void
    {
        config(['pdf_service.hmac.keys' => '']);

        $report = $this->inspector([])->localConfiguration();

        $this->assertFalse($report['ok']);
        $this->assertStringContainsString('is not configured: primary', $report['problem']);
        $this->assertStringContainsString('PDF_SERVICE_HMAC_KEYS', $report['problem']);
    }

    public function test_local_configuration_skips_the_key_check_when_the_service_is_disabled(): void
    {
        config(['pdf_service.enabled' => false, 'pdf_service.hmac.keys' => '']);

        $report = $this->inspector([])->localConfiguration();

        $this->assertTrue($report['ok']);
        $this->assertNull($report['secret_bytes']);
    }

    public function test_signing_service_health_projects_the_readiness_flags(): void
    {
        $inspector = $this->inspector([
            new Response(503, ['Content-Type' => 'application/json'], (string) json_encode([
                'status' => 'not_ready',
                'hmac_ready' => true,
                'signing_material_ready' => false,
                'service' => 'pdf-renderer-java',
            ])),
        ]);

        $health = $inspector->signingServiceHealth();

        $this->assertTrue($health['reachable']);
        $this->assertSame('not_ready', $health['status']);
        $this->assertSame(['hmac_ready' => true, 'signing_material_ready' => false], $health['flags']);
    }

    public function test_hmac_agreement_detects_a_key_mismatch(): void
    {
        $inspector = $this->inspector([
            new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => 'PDF_HMAC_KEY_UNKNOWN',
            ])),
        ]);

        $agreement = $inspector->hmacAgreement();

        $this->assertTrue($agreement['checked']);
        $this->assertFalse($agreement['agreed']);
        $this->assertSame('PDF_HMAC_KEY_UNKNOWN', $agreement['detail']);
    }

    public function test_hmac_agreement_treats_a_business_rejection_as_proof_the_signature_was_accepted(): void
    {
        $inspector = $this->inspector([
            new Response(404, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => 'EXECUTION_NOT_FOUND',
            ])),
        ]);

        $agreement = $inspector->hmacAgreement();

        $this->assertTrue($agreement['checked']);
        $this->assertTrue($agreement['agreed']);
    }

    public function test_hmac_agreement_does_not_blame_the_key_for_a_nonce_store_outage(): void
    {
        $inspector = $this->inspector([
            new Response(503, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => 'PDF_HMAC_NONCE_STORE_UNAVAILABLE',
            ])),
        ]);

        $agreement = $inspector->hmacAgreement();

        $this->assertFalse($agreement['checked']);
        $this->assertFalse($agreement['agreed']);
        $this->assertStringContainsString('nonce store is unavailable', $agreement['detail']);
        $this->assertStringNotContainsString('mismatch', $agreement['detail']);
    }

    public function test_hmac_agreement_is_skipped_when_the_local_key_is_missing(): void
    {
        config(['pdf_service.hmac.keys' => '']);

        $agreement = $this->inspector([])->hmacAgreement();

        $this->assertFalse($agreement['checked']);
        $this->assertFalse($agreement['agreed']);
        $this->assertStringContainsString('local HMAC configuration is invalid', $agreement['detail']);
    }

    /** @param list<Response> $responses */
    private function inspector(array $responses): PdfRuntimeInspector
    {
        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
            'base_uri' => 'http://pdf-service.test/',
            'http_errors' => false,
        ]);

        return new PdfRuntimeInspector(new PdfRendererClient($client));
    }
}

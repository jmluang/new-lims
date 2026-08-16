<?php

namespace App\Services\Pdf;

use RuntimeException;
use Throwable;

/**
 * Operator-facing readiness report for the PDF signing runtime.
 *
 * The HMAC secret is shared between this application and the Java signing
 * service through two independent channels, and nothing else verifies that they
 * match. A missing key on this side is otherwise invisible until the first call
 * reaches the signing boundary, so surface it here instead.
 */
final class PdfRuntimeInspector
{
    public function __construct(private readonly PdfRendererClient $renderer) {}

    /**
     * Local configuration only; performs no network calls so it stays usable
     * when the signing service is down. Never returns the secret itself.
     *
     * @return array{ok: bool, enabled: bool, hmac_enabled: bool, active_key_id: string, secret_bytes: ?int, problem: ?string}
     */
    public function localConfiguration(): array
    {
        $enabled = (bool) config('pdf_service.enabled');
        $hmacEnabled = (bool) config('pdf_service.hmac.enabled');
        $activeKeyId = (string) config('pdf_service.hmac.active_key_id');
        $report = [
            'ok' => true,
            'enabled' => $enabled,
            'hmac_enabled' => $hmacEnabled,
            'active_key_id' => $activeKeyId,
            'secret_bytes' => null,
            'problem' => null,
        ];

        if (! $enabled || ! $hmacEnabled) {
            return $report;
        }

        try {
            $report['secret_bytes'] = $this->renderer->activeHmacSecretBytes();
        } catch (RuntimeException $exception) {
            $report['ok'] = false;
            $report['problem'] = $exception->getMessage()
                .' Set PDF_SERVICE_HMAC_KEYS in the backend .env to the same key-id:base64-secret'
                .' entry the Java signing service uses.';
        }

        return $report;
    }

    /**
     * @return array{reachable: bool, status: ?string, flags: array<string, bool>, problem: ?string}
     */
    public function signingServiceHealth(): array
    {
        try {
            $payload = $this->renderer->healthReport();
        } catch (Throwable $exception) {
            return ['reachable' => false, 'status' => null, 'flags' => [], 'problem' => $exception->getMessage()];
        }

        $flags = [];
        foreach ($payload as $key => $value) {
            if (is_bool($value)) {
                $flags[$key] = $value;
            }
        }

        return [
            'reachable' => true,
            'status' => isset($payload['status']) ? (string) $payload['status'] : null,
            'flags' => $flags,
            'problem' => null,
        ];
    }

    /**
     * `checked` is false whenever the probe could not reach a verdict, so a
     * transport outage is never reported as the two sides disagreeing.
     *
     * @return array{checked: bool, agreed: bool, detail: string}
     */
    public function hmacAgreement(): array
    {
        if (! $this->localConfiguration()['ok']) {
            return ['checked' => false, 'agreed' => false, 'detail' => 'skipped: local HMAC configuration is invalid'];
        }

        try {
            $handshake = $this->renderer->hmacHandshake();
        } catch (Throwable $exception) {
            return ['checked' => false, 'agreed' => false, 'detail' => $exception->getMessage()];
        }

        return [
            'checked' => ! $handshake['blocked'],
            'agreed' => $handshake['agreed'],
            'detail' => $handshake['detail'],
        ];
    }
}

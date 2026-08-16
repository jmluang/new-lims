<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Pdf\PdfRendererClient;
use App\Services\Pdf\PdfRuntimeInspector;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PdfServiceHealthController extends Controller
{
    public function __invoke(
        PdfRendererClient $pdfRendererClient,
        PdfRuntimeInspector $inspector,
    ): JsonResponse {
        // A missing local HMAC key never reaches the signing service, so check it
        // here rather than letting the first real signing call fail on it.
        $configuration = $inspector->localConfiguration();

        if (! $configuration['ok']) {
            return response()->json([
                'data' => [
                    'healthy' => false,
                    'message' => $configuration['problem'],
                    'configuration' => $configuration,
                ],
            ], 503);
        }

        try {
            $healthy = $pdfRendererClient->health();
        } catch (RuntimeException $exception) {
            return response()->json([
                'data' => [
                    'healthy' => false,
                    'message' => $exception->getMessage(),
                    'configuration' => $configuration,
                ],
            ], 503);
        }

        return response()->json([
            'data' => [
                'healthy' => $healthy,
                'configuration' => $configuration,
            ],
        ], $healthy ? 200 : 503);
    }
}

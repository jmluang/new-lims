<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Pdf\PdfRendererClient;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PdfServiceHealthController extends Controller
{
    public function __invoke(PdfRendererClient $pdfRendererClient): JsonResponse
    {
        try {
            $healthy = $pdfRendererClient->health();
        } catch (RuntimeException $exception) {
            return response()->json([
                'data' => [
                    'healthy' => false,
                    'message' => $exception->getMessage(),
                ],
            ], 503);
        }

        return response()->json([
            'data' => [
                'healthy' => $healthy,
            ],
        ], $healthy ? 200 : 503);
    }
}

<?php

namespace App\Services\Pdf;

use RuntimeException;

final class PdfRendererHttpException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $responseBody,
    ) {
        parent::__construct("PDF service returned HTTP {$statusCode}.");
    }
}

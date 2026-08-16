<?php

namespace App\Services\Pdf;

use Illuminate\Validation\ValidationException;
use Normalizer;

final class ReportNumberNormalizer
{
    public const VERSION = 'nfkc-trim-ascii-upper-v1';

    public function normalize(string $displayValue): string
    {
        $normalized = Normalizer::normalize($displayValue, Normalizer::FORM_KC);

        if (! is_string($normalized)) {
            throw ValidationException::withMessages(['report_number' => ['report_number_invalid_unicode']]);
        }

        $normalized = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $normalized) ?? '';

        if ($normalized === '' || preg_match('/[\p{Cc}\p{Cf}]/u', $normalized) === 1) {
            throw ValidationException::withMessages(['report_number' => ['report_number_invalid']]);
        }

        if (mb_strlen($normalized, 'UTF-8') > 128) {
            throw ValidationException::withMessages(['report_number' => ['report_number_too_long']]);
        }

        return preg_replace_callback('/[a-z]/', static fn (array $match): string => strtoupper($match[0]), $normalized)
            ?? $normalized;
    }
}

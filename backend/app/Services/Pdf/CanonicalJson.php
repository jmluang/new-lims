<?php

namespace App\Services\Pdf;

use JsonException;

final class CanonicalJson
{
    private const MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    /**
     * Deterministic UTF-8 JSON for hashes. Object keys are sorted recursively;
     * array order remains semantically significant.
     *
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS,
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new JsonException('Floating-point values are forbidden in canonical PDF manifests.');
        }
        if (is_int($value) && ($value < -self::MAX_SAFE_INTEGER || $value > self::MAX_SAFE_INTEGER)) {
            throw new JsonException('Integer exceeds the RFC 8785 interoperable range.');
        }
        if (is_string($value) && preg_match('//u', $value) !== 1) {
            throw new JsonException('Canonical PDF manifest strings must be valid UTF-8.');
        }
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || preg_match('/^[\x20-\x7E]+$/', $key) !== 1) {
                throw new JsonException('Canonical PDF manifest object keys must be printable ASCII.');
            }
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}

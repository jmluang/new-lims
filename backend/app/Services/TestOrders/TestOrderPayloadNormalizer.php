<?php

namespace App\Services\TestOrders;

class TestOrderPayloadNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        return $this->normalizeValue($payload);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): mixed => $this->normalizeValue($item))
                ->all();
        }

        if ($value === '') {
            return null;
        }

        return $value;
    }
}

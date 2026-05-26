<?php

namespace App\Services\Authorization;

class EffectivePermissions
{
    /**
     * @param  array<string, array{actions: array<string, bool>, fields: array<string, array<string, bool>>}>  $resources
     */
    public function __construct(private readonly array $resources) {}

    public function allows(string $resource, ?string $field, string $action): bool
    {
        if ($field !== null) {
            return $this->resources[$resource]['fields'][$field][$action] ?? false;
        }

        return $this->resources[$resource]['actions'][$action] ?? false;
    }

    /**
     * @return array{resources: array<string, array{actions: array<string, bool>, fields: array<string, array<string, bool>>}>}
     */
    public function toArray(): array
    {
        return ['resources' => $this->resources];
    }
}

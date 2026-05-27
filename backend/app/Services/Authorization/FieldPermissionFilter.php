<?php

namespace App\Services\Authorization;

use App\Models\User;

class FieldPermissionFilter
{
    public function __construct(private readonly PermissionCatalog $permissionCatalog) {}

    public function can(User $user, string $resource, string $field, string $action): bool
    {
        return $user->hasRole('super_admin') || $user->can("{$resource}.field.{$field}.{$action}");
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function filterRecord(User $user, string $resource, array $record): array
    {
        foreach ($this->fieldNames($resource) as $field) {
            if (! $this->can($user, $resource, $field, 'read')) {
                $record[$field] = null;
            }
        }

        $record['_field_permissions'] = $this->meta($user, $resource);

        return $record;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function meta(User $user, string $resource): array
    {
        $meta = [];

        foreach ($this->permissionCatalog->fieldActions()[$resource] ?? [] as $field => $actions) {
            foreach ($actions as $action) {
                $meta[$field][$action] = $this->can($user, $resource, $field, $action);
            }

            $meta[$field]['hidden'] = ! ($meta[$field]['read'] ?? false);
        }

        return $meta;
    }

    /**
     * @return array<int, string>
     */
    public function exportableFields(User $user, string $resource, array $baseFields): array
    {
        $fields = $baseFields;

        foreach ($this->fieldNames($resource) as $field) {
            if ($this->can($user, $resource, $field, 'export')) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array<int, string>
     */
    public function forbiddenUpdateFields(User $user, string $resource, array $payload): array
    {
        return collect($this->fieldNames($resource))
            ->filter(fn (string $field): bool => array_key_exists($field, $payload) && ! $this->can($user, $resource, $field, 'update'))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function fieldNames(string $resource): array
    {
        return array_keys($this->permissionCatalog->fieldActions()[$resource] ?? []);
    }
}

<?php

namespace App\Services\Authorization;

class PermissionCatalog
{
    /**
     * @return array<string, array<int, string>>
     */
    public function resourceActions(): array
    {
        return [
            'system.users' => ['read', 'create', 'update', 'delete', 'export'],
            'system.departments' => ['read', 'create', 'update', 'delete'],
            'system.groups' => ['read', 'create', 'update', 'delete'],
            'system.audit_logs' => ['read', 'export'],
            'system.dictionaries' => ['read', 'create', 'update', 'delete'],
            'system.backups' => ['read', 'create', 'restore'],
            'customers' => ['read', 'create', 'update', 'delete', 'export'],
            'customer_contacts' => ['read', 'create', 'update', 'delete', 'export'],
            'standards' => ['read', 'create', 'update', 'delete', 'export'],
            'standard_catalogs' => ['read', 'create', 'update', 'delete'],
            'standard_items' => ['read', 'create', 'update', 'delete'],
            'test_orders' => ['read', 'create', 'update', 'delete', 'export'],
            'test_order_standards' => ['read', 'create', 'update', 'delete'],
            'test_order_samples' => ['read', 'create', 'update', 'delete'],
            'samples' => ['read', 'receive', 'update', 'export'],
            'sample_flows' => ['read', 'create'],
            'equipment' => ['read', 'create', 'update', 'delete', 'export'],
            'equipment_locations' => ['read', 'create', 'update', 'delete'],
            'equipment_labels' => ['read', 'print'],
            'temp_humidity_records' => ['read', 'create', 'update', 'delete'],
        ];
    }

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    public function fieldActions(): array
    {
        return [
            'system.users' => [
                'phone' => ['read', 'update'],
                'email' => ['read', 'update'],
            ],
            'customers' => [
                'credit_code' => ['read', 'update', 'export'],
                'phone' => ['read', 'update', 'export'],
                'email' => ['read', 'update', 'export'],
            ],
            'customer_contacts' => [
                'phone' => ['read', 'update', 'export'],
                'email' => ['read', 'update', 'export'],
            ],
            'equipment' => [
                'serial_no' => ['read', 'update', 'export'],
                'legacy_placement' => ['read', 'update', 'export'],
                'device_image' => ['read', 'update'],
                'manual_files' => ['read', 'update'],
                'instruction_files' => ['read', 'update'],
                'calibration_files' => ['read', 'update'],
                'other_files' => ['read', 'update'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function permissionNames(): array
    {
        $permissionNames = [];

        foreach ($this->resourceActions() as $resource => $actions) {
            foreach ($actions as $action) {
                $permissionNames[] = "{$resource}.{$action}";
            }
        }

        foreach ($this->fieldActions() as $resource => $fields) {
            foreach ($fields as $field => $actions) {
                foreach ($actions as $action) {
                    $permissionNames[] = "{$resource}.field.{$field}.{$action}";
                }
            }
        }

        return array_values(array_unique($permissionNames));
    }

    /**
     * @return array{resources: array<string, array{actions: array<int, string>, fields: array<string, array<int, string>>}>}
     */
    public function toArray(): array
    {
        $resources = [];

        foreach ($this->resourceActions() as $resource => $actions) {
            $resources[$resource] = [
                'actions' => $actions,
                'fields' => $this->fieldActions()[$resource] ?? [],
            ];
        }

        foreach ($this->fieldActions() as $resource => $fields) {
            $resources[$resource] ??= [
                'actions' => [],
                'fields' => [],
            ];

            $resources[$resource]['fields'] = $fields;
        }

        return ['resources' => $resources];
    }
}

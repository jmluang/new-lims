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
            'system.backups' => ['read', 'create', 'restore'],
            'customers' => ['read', 'create', 'update', 'delete', 'export'],
            'customer_contacts' => ['read', 'create', 'update', 'delete', 'export'],
            'standards' => ['read', 'create', 'update', 'delete', 'export'],
            'standard_catalogs' => ['read', 'create', 'update', 'delete'],
            'standard_items' => ['read', 'create', 'update', 'delete'],
            'test_orders' => ['read', 'create', 'update', 'delete', 'export', 'notify', 'print'],
            'test_order_standards' => ['read', 'create', 'update', 'delete'],
            'test_order_samples' => ['read', 'create', 'update', 'delete'],
            'samples' => ['read', 'receive', 'update', 'export'],
            'sample_labels' => ['read', 'print'],
            'sample_flows' => ['read', 'create', 'return_room'],
            'equipment' => ['read', 'create', 'update', 'delete', 'export'],
            'equipment_locations' => ['read', 'create', 'update', 'delete'],
            'equipment_systems' => ['read', 'create', 'update', 'delete'],
            'equipment_labels' => ['read', 'print'],
            'equipment_usage_records' => ['read', 'create', 'update', 'delete'],
            'temp_humidity_records' => ['read', 'create', 'update', 'delete'],
            'calibration_projects' => ['read', 'create', 'update', 'delete'],
            'calibration_project_labels' => ['print'],
            'equipment_calibrations' => ['read', 'create', 'update', 'delete'],
            'pdf_signing' => ['read', 'create'],
            'pdf_verification' => ['read', 'create'],
            'pdf_files' => ['read', 'download'],
            'pdf_verification_logs' => ['read', 'download'],
            'pdf_digital_signatures' => ['read', 'create', 'update', 'delete'],
            'pdf_perforation_stamps' => ['read', 'create', 'update', 'delete'],
            'pdf_function_stamps' => ['read', 'create', 'update', 'delete'],
            'pdf_certificate_templates' => ['read', 'create', 'update', 'delete'],
            'pdf.workflow' => ['read', 'create', 'cancel'],
            'pdf.request' => ['read', 'sign_assigned', 'reject'],
            'pdf.organization_key' => ['use'],
            'pdf.revision' => ['download'],
            'pdf.manual_review' => ['resolve'],
            'pdf.evidence_hold' => ['manage'],
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
                'device_image' => ['read', 'update'],
                'manual_files' => ['read', 'update'],
                'instruction_files' => ['read', 'update'],
                'calibration_files' => ['read', 'update'],
                'other_files' => ['read', 'update'],
            ],
            'equipment_calibrations' => [
                'attachment_files' => ['read', 'update'],
                'photo_files' => ['read', 'update'],
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

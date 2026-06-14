<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Authorization\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CanonicalAcceptanceSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seedPermissions();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $catalogPermissions = app(PermissionCatalog::class)->permissionNames();
        $superAdmin = $this->group(
            'super_admin',
            $catalogPermissions,
            displayName: '超级管理员',
            systemKey: 'super_admin',
            isSystem: true,
        );
        $systemAdmin = $this->group('system_admin', [
            'system.users.read',
            'system.users.create',
            'system.users.update',
            'system.departments.read',
            'system.departments.create',
            'system.departments.update',
            'system.groups.read',
            'system.groups.create',
            'system.groups.update',
            'system.audit_logs.read',
            'system.audit_logs.export',
            'system.backups.read',
            'system.backups.create',
            'system.backups.restore',
        ]);
        $customerViewer = $this->group('customer_viewer', [
            'customers.read',
            'customer_contacts.read',
        ]);
        $customerEditor = $this->group('customer_editor', [
            'customers.read',
            'customers.create',
            'customers.update',
            'customer_contacts.read',
            'customer_contacts.create',
            'customer_contacts.update',
        ]);
        $equipmentManager = $this->group('equipment_manager', [
            'equipment.read',
            'equipment.create',
            'equipment.update',
            'equipment.delete',
            'equipment.export',
            'equipment.field.serial_no.read',
            'equipment.field.serial_no.update',
            'equipment.field.device_image.read',
            'equipment.field.device_image.update',
            'equipment.field.manual_files.read',
            'equipment.field.manual_files.update',
            'equipment.field.instruction_files.read',
            'equipment.field.instruction_files.update',
            'equipment.field.calibration_files.read',
            'equipment.field.calibration_files.update',
            'equipment.field.other_files.read',
            'equipment.field.other_files.update',
            'equipment_locations.read',
            'equipment_locations.create',
            'equipment_locations.update',
            'equipment_locations.delete',
            'equipment_systems.read',
            'equipment_systems.create',
            'equipment_systems.update',
            'equipment_systems.delete',
            'equipment_labels.read',
            'equipment_labels.print',
            'equipment_usage_records.read',
            'equipment_usage_records.create',
            'equipment_usage_records.update',
            'equipment_usage_records.delete',
            'temp_humidity_records.read',
            'temp_humidity_records.create',
            'temp_humidity_records.update',
            'temp_humidity_records.delete',
            'calibration_projects.read',
            'calibration_projects.create',
            'calibration_projects.update',
            'calibration_projects.delete',
            'calibration_project_labels.print',
            'equipment_calibrations.read',
            'equipment_calibrations.create',
            'equipment_calibrations.update',
            'equipment_calibrations.delete',
            'equipment_calibrations.field.attachment_files.read',
            'equipment_calibrations.field.attachment_files.update',
            'equipment_calibrations.field.photo_files.read',
            'equipment_calibrations.field.photo_files.update',
        ]);
        $testOrderManager = $this->group('test_order_manager', [
            'standards.read',
            'test_orders.read',
            'test_orders.create',
            'test_orders.update',
            'test_orders.delete',
            'test_orders.export',
            'test_order_standards.read',
            'test_order_standards.create',
            'test_order_standards.update',
            'test_order_standards.delete',
            'test_order_samples.read',
            'test_order_samples.create',
            'test_order_samples.update',
            'test_order_samples.delete',
            'samples.receive',
            'sample_labels.read',
            'sample_labels.print',
        ]);
        $sampleManager = $this->group('sample_manager', [
            'samples.read',
            'samples.receive',
            'samples.update',
            'samples.export',
            'sample_labels.read',
            'sample_labels.print',
            'sample_flows.read',
            'sample_flows.create',
            'equipment_locations.read',
            'equipment_usage_records.read',
            'equipment_usage_records.create',
            'equipment_usage_records.update',
        ]);
        $auditor = $this->group('auditor', [
            'system.audit_logs.read',
            'system.audit_logs.export',
        ]);

        $this->user('super_admin@example.test', 'Super Admin', 'active', $superAdmin);
        $this->user('system_admin@example.test', 'System Admin', 'active', $systemAdmin);
        $this->user('customer_viewer@example.test', 'Customer Viewer', 'active', $customerViewer);
        $this->user('customer_editor@example.test', 'Customer Editor', 'active', $customerEditor);
        $this->user('equipment_manager@example.test', 'Equipment Manager', 'active', $equipmentManager);
        $this->user('test_order_manager@example.test', 'Test Order Manager', 'active', $testOrderManager);
        $this->user('sample_manager@example.test', 'Sample Manager', 'active', $sampleManager);
        $this->user('auditor@example.test', 'Auditor', 'active', $auditor);
        $this->user('locked_user@example.test', 'Locked User', 'locked', $customerViewer, locked: true);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedPermissions(): void
    {
        foreach (app(PermissionCatalog::class)->permissionNames() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function group(string $name, array $permissions = [], ?string $displayName = null, ?string $systemKey = null, bool $isSystem = false): Role
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['status' => 'active']
        );

        $role->forceFill([
            'display_name' => $displayName ?? $role->display_name ?? $name,
            'system_key' => $systemKey,
            'is_system' => $isSystem,
            'status' => 'active',
        ])->save();
        $role->syncPermissions($permissions);

        return $role;
    }

    private function user(string $email, string $name, string $status, Role $role, bool $locked = false): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'status' => $status,
                'must_change_password' => false,
                'locked_at' => $locked ? now() : null,
                'lock_reason' => $locked ? 'canonical_acceptance_locked_user' : null,
            ],
        );

        $user->syncRoles([$role]);

        return $user;
    }
}

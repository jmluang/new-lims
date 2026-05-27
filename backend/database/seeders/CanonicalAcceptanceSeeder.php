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

        $superAdmin = $this->group('super_admin');
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
            'system.dictionaries.read',
            'system.dictionaries.create',
            'system.dictionaries.update',
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
            'equipment.field.legacy_placement.read',
            'equipment.field.legacy_placement.update',
            'equipment_locations.read',
            'equipment_locations.create',
            'equipment_locations.update',
            'equipment_locations.delete',
            'equipment_labels.read',
            'equipment_labels.print',
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
    private function group(string $name, array $permissions = []): Role
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['status' => 'active']
        );

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

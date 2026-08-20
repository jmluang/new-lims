<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const NAMES = [
        'integrating_sphere_inspection_records.read',
        'integrating_sphere_inspection_records.create',
        'integrating_sphere_inspection_records.update',
        'integrating_sphere_inspection_records.delete',
    ];

    /**
     * Existing deployments already carry the canonical roles, so the new resource
     * is granted the same way the equipment usage workflow is: every action to the
     * equipment manager, read/create/update to the sample manager.
     */
    private const ROLE_GRANTS = [
        'super_admin' => self::NAMES,
        'equipment_manager' => self::NAMES,
        'sample_manager' => [
            'integrating_sphere_inspection_records.read',
            'integrating_sphere_inspection_records.create',
            'integrating_sphere_inspection_records.update',
        ],
    ];

    public function up(): void
    {
        $permissionIds = [];

        foreach (self::NAMES as $name) {
            $permissionIds[$name] = DB::table('permissions')->where(compact('name'))->where('guard_name', 'web')->value('id')
                ?: DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        foreach (self::ROLE_GRANTS as $roleName => $names) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($names as $name) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'permission_id' => $permissionIds[$name],
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::NAMES)->where('guard_name', 'web')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

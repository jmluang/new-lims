<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the PDF tamper-proof permissions and grants them to super_admin so
 * an already-seeded install picks up the new module without re-seeding.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const RESOURCE_ACTIONS = [
        'pdf_signing' => ['read', 'create'],
        'pdf_verification' => ['read', 'create'],
        'pdf_files' => ['read', 'download'],
        'pdf_verification_logs' => ['read', 'download'],
        'pdf_digital_signatures' => ['read', 'create', 'update', 'delete'],
        'pdf_perforation_stamps' => ['read', 'create', 'update', 'delete'],
        'pdf_function_stamps' => ['read', 'create', 'update', 'delete'],
        'pdf_certificate_templates' => ['read', 'create', 'update', 'delete'],
    ];

    public function up(): void
    {
        $now = now();
        $superAdminRoleId = DB::table('roles')
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->value('id');

        foreach ($this->permissionNames() as $name) {
            $permissionId = DB::table('permissions')
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->value('id');

            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($superAdminRoleId) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $superAdminRoleId,
                ], [
                    'permission_id' => $permissionId,
                    'role_id' => $superAdminRoleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->permissionNames())
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        $names = [];

        foreach (self::RESOURCE_ACTIONS as $resource => $actions) {
            foreach ($actions as $action) {
                $names[] = "{$resource}.{$action}";
            }
        }

        return $names;
    }
};

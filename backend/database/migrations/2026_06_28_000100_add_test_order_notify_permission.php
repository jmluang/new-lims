<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissionId = DB::table('permissions')->where('name', 'test_orders.notify')->where('guard_name', 'web')->value('id');

        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'test_orders.notify',
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('roles')
            ->whereIn('name', ['super_admin', 'test_order_manager'])
            ->where('guard_name', 'web')
            ->pluck('id')
            ->each(function (int $roleId) use ($permissionId, $now): void {
                DB::table('role_has_permissions')->updateOrInsert(
                    [
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ],
                    [
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ],
                );
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'test_orders.notify')->where('guard_name', 'web')->value('id');

        if ($permissionId) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

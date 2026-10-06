<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissions')->where('name', 'pdf_files.delete')->where('guard_name', 'web')->value('id')
            ?: DB::table('permissions')->insertGetId([
                'name' => 'pdf_files.delete',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        $roleId = DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->value('id');
        if ($roleId) {
            DB::table('role_has_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('name', 'pdf_files.delete')->where('guard_name', 'web')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

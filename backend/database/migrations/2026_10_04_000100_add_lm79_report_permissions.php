<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const NAMES = ['lm79_reports.read', 'lm79_reports.create', 'lm79_reports.update', 'lm79_reports.delete', 'lm79_reports.print'];

    public function up(): void
    {
        foreach (self::NAMES as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
                ?: DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            foreach (['super_admin', 'sample_manager', 'pdf_planner'] as $role) {
                $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
                if ($roleId) {
                    DB::table('role_has_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id]);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::NAMES)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * A role for the people who sign reports.
 *
 * The signing permissions existed but only super_admin held them, so a report
 * could be assigned to someone who could not open the signing page — the
 * workflow then waited on a signature they were unable to give. None of the
 * existing roles fit: they are scoped to business domains (customers,
 * equipment, samples), and signing a report is its own responsibility.
 *
 * Deliberately excludes pdf.workflow.* — planning who signs is a separate job
 * from signing, and a signer should not be able to reassign their own report.
 */
return new class extends Migration
{
    private const ROLE = 'pdf_signer';

    private const NAMES = [
        'pdf.request.read',
        'pdf.request.sign_assigned',
        'pdf.request.reject',
        'pdf.organization_key.use',
        'pdf.revision.download',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', self::ROLE)->where('guard_name', 'web')->value('id')
            ?: DB::table('roles')->insertGetId([
                'name' => self::ROLE,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach (self::NAMES as $name) {
            $permissionId = DB::table('permissions')->where(compact('name'))->where('guard_name', 'web')->value('id')
                ?: DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('role_has_permissions')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', self::ROLE)->where('guard_name', 'web')->value('id');

        if ($roleId !== null) {
            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
            DB::table('model_has_roles')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

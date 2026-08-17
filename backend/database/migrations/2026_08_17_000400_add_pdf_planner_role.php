<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * A role for the people who prepare reports for signing.
 *
 * The counterpart to pdf_signer: planning decides which report goes out, who
 * signs it and where, and it was reachable only by a super admin. Splitting it
 * out lets an ordinary operator prepare reports without also holding evidence
 * holds, manual review resolution and every other administrative power.
 *
 * Deliberately excludes pdf.request.sign_assigned — planning a report is not
 * permission to sign it. Someone who does both holds both roles, and that is
 * then a visible choice rather than an accident of the permission model.
 */
return new class extends Migration
{
    private const ROLE = 'pdf_planner';

    private const NAMES = [
        'pdf.workflow.read',
        'pdf.workflow.create',
        'pdf.workflow.cancel',
        'pdf.document.read',
        'pdf.document.update',
        'pdf.document.delete',
        'pdf.request.read',
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

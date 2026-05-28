<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('name');
            $table->string('system_key')->nullable()->after('display_name');
            $table->unique(['display_name', 'guard_name'], 'roles_display_name_guard_name_unique');
            $table->unique('system_key', 'roles_system_key_unique');
        });

        DB::table('roles')
            ->orderBy('id')
            ->select(['id', 'name'])
            ->get()
            ->each(fn (object $role) => DB::table('roles')->where('id', $role->id)->update([
                'display_name' => $role->name,
            ]));

        $superAdminRole = $this->superAdminRole();

        if ($superAdminRole) {
            $update = [
                'display_name' => $superAdminRole->name === 'super_admin' ? 'Super Admin' : $superAdminRole->name,
                'system_key' => 'super_admin',
                'is_system' => true,
                'status' => 'active',
            ];

            $nameTaken = DB::table('roles')
                ->where('name', 'super_admin')
                ->where('guard_name', $superAdminRole->guard_name)
                ->where('id', '!=', $superAdminRole->id)
                ->exists();

            if (! $nameTaken) {
                $update['name'] = 'super_admin';
            }

            DB::table('roles')->where('id', $superAdminRole->id)->update($update);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_display_name_guard_name_unique');
            $table->dropUnique('roles_system_key_unique');
            $table->dropColumn(['display_name', 'system_key']);
        });
    }

    private function superAdminRole(): ?object
    {
        foreach ($this->superAdminNameCandidates() as $name) {
            $role = DB::table('roles')
                ->where('guard_name', 'web')
                ->where('name', $name)
                ->first();

            if ($role) {
                return $role;
            }
        }

        $superAdminUser = DB::table('users')->where('email', 'super_admin@example.test')->first();

        if (! $superAdminUser) {
            return null;
        }

        return DB::table('roles')
            ->join('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.guard_name', 'web')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->where('model_has_roles.model_id', $superAdminUser->id)
            ->select('roles.*')
            ->orderBy('roles.id')
            ->first();
    }

    /**
     * @return array<int, string>
     */
    private function superAdminNameCandidates(): array
    {
        return [
            'super_admin',
            'Super Admin',
            '超级管理员',
            '中文超级管理员',
            '超級管理員',
        ];
    }
};

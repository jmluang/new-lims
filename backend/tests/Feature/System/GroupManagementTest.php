<?php

namespace Tests\Feature\System;

use App\Models\User;
use App\Services\Authorization\EffectivePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GroupManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_can_manage_group_permissions_and_disabled_group_stops_granting_permissions(): void
    {
        $admin = $this->userWithPermissions([
            'system.groups.read',
            'system.groups.create',
            'system.groups.update',
        ]);

        $createResponse = $this->postJsonAs($admin, '/api/system/groups', [
            'name' => 'customer_manager',
            'description' => 'Customer manager',
            'status' => 'active',
            'permissions' => ['customers.read', 'customers.field.phone.read'],
        ])->assertCreated();

        $groupId = $createResponse->json('data.id');
        $member = User::factory()->create();
        $member->assignRole(Role::query()->findOrFail($groupId));

        $this->assertTrue(app(EffectivePermissionService::class)->forUser($member)->allows('customers', null, 'read'));

        $this->putJsonAs($admin, "/api/system/groups/{$groupId}/permissions", [
            'permissions' => ['customers.read', 'customers.export'],
        ])->assertOk()
            ->assertJsonPath('data.permissions', ['customers.export', 'customers.read']);

        $this->putJsonAs($admin, "/api/system/groups/{$groupId}", [
            'description' => 'Disabled customer manager',
            'status' => 'disabled',
        ])->assertOk();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(app(EffectivePermissionService::class)->forUser($member->fresh())->allows('customers', null, 'read'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'system.groups.create', 'subject_id' => (string) $groupId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.groups.permissions.update', 'subject_id' => (string) $groupId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.groups.update', 'subject_id' => (string) $groupId]);
    }

    public function test_system_group_display_name_update_does_not_change_authorization_key(): void
    {
        $admin = $this->userWithPermissions([
            'system.groups.read',
            'system.groups.update',
        ]);
        $superAdminUser = User::factory()->create(['email' => 'super_admin@example.test']);
        $superAdminGroup = Role::create([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'display_name' => 'Super Admin',
            'system_key' => 'super_admin',
            'is_system' => true,
            'status' => 'active',
        ]);
        $superAdminUser->assignRole($superAdminGroup);

        $this->putJsonAs($admin, "/api/system/groups/{$superAdminGroup->id}", [
            'name' => '超级管理员',
            'description' => 'Full system access',
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.name', '超级管理员')
            ->assertJsonPath('data.key', 'super_admin');

        $this->assertDatabaseHas('roles', [
            'id' => $superAdminGroup->id,
            'name' => 'super_admin',
            'display_name' => '超级管理员',
            'system_key' => 'super_admin',
        ]);
        $this->assertTrue(app(EffectivePermissionService::class)->forUser($superAdminUser->fresh())->allows('customers', null, 'delete'));

        Sanctum::actingAs($superAdminUser->fresh());
        $this->getJson('/api/system/groups')->assertOk();
        $this->getJson('/api/system/users')->assertOk()
            ->assertJsonPath('meta.fields.phone.read', true);
        $this->getJson('/api/customers')->assertOk()
            ->assertJsonPath('meta.fields.phone.read', true);
    }

    public function test_disabled_group_permissions_do_not_authorize_api_requests(): void
    {
        $user = User::factory()->create();
        $group = Role::create([
            'name' => 'disabled_group_admin',
            'guard_name' => 'web',
            'status' => 'disabled',
        ]);
        $group->givePermissionTo(Permission::findOrCreate('system.groups.read', 'web'));
        $user->assignRole($group);

        Sanctum::actingAs($user);

        $this->getJson('/api/system/groups')->assertForbidden();
    }

    public function test_direct_permissions_still_authorize_api_requests(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('system.groups.read', 'web'));

        Sanctum::actingAs($user);

        $this->getJson('/api/system/groups')->assertOk();
    }

    public function test_admin_can_delete_non_system_group(): void
    {
        $admin = $this->userWithPermissions(['system.groups.read', 'system.groups.delete']);
        $group = Role::create([
            'name' => 'obsolete_group',
            'guard_name' => 'web',
            'display_name' => 'Obsolete Group',
            'status' => 'active',
        ]);
        $group->givePermissionTo(Permission::findOrCreate('customers.read', 'web'));

        $this->deleteJsonAs($admin, "/api/system/groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('roles', ['id' => $group->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.groups.delete', 'subject_id' => (string) $group->id]);
    }

    public function test_system_group_cannot_be_deleted(): void
    {
        $admin = $this->userWithPermissions(['system.groups.delete']);
        $group = Role::create([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'display_name' => 'Super Admin',
            'system_key' => 'super_admin',
            'is_system' => true,
            'status' => 'active',
        ]);

        $this->deleteJsonAs($admin, "/api/system/groups/{$group->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['group']);

        $this->assertDatabaseHas('roles', ['id' => $group->id]);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_group_admin_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function postJsonAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->postJson($uri, $data);
    }

    private function putJsonAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->putJson($uri, $data);
    }

    private function deleteJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->deleteJson($uri);
    }
}

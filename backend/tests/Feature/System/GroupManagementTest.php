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
}

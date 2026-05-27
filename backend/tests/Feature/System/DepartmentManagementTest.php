<?php

namespace Tests\Feature\System;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_can_create_update_disable_and_list_department_tree(): void
    {
        $admin = $this->userWithPermissions([
            'system.departments.read',
            'system.departments.create',
            'system.departments.update',
            'system.departments.delete',
        ]);

        $rootId = $this->postJsonAs($admin, '/api/system/departments', [
            'name' => 'Head Lab',
            'code' => 'HEAD',
            'sort_order' => 1,
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $childId = $this->postJsonAs($admin, '/api/system/departments', [
            'parent_id' => $rootId,
            'name' => 'Chemistry Lab',
            'code' => 'CHEM',
            'sort_order' => 1,
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $this->putJsonAs($admin, "/api/system/departments/{$childId}", [
            'parent_id' => $rootId,
            'name' => 'Chemistry Lab Updated',
            'code' => 'CHEM',
            'sort_order' => 2,
            'status' => 'active',
        ])->assertOk();

        $this->deleteJsonAs($admin, "/api/system/departments/{$childId}")->assertOk();

        $this->getJsonAs($admin, '/api/system/departments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $rootId)
            ->assertJsonPath('data.0.children.0.id', $childId)
            ->assertJsonPath('data.0.children.0.status', 'disabled');

        $this->assertDatabaseHas('audit_logs', ['action' => 'system.departments.create', 'subject_id' => (string) $rootId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.departments.update', 'subject_id' => (string) $childId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.departments.disable', 'subject_id' => (string) $childId]);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_department_admin_'.str()->random(8), 'guard_name' => 'web']);
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

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }
}

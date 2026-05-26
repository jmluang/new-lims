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

class EffectivePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_user_receives_union_of_permissions_from_multiple_groups(): void
    {
        $user = User::factory()->create();
        $viewer = $this->createGroup('customer_viewer', ['customers.read']);
        $equipmentManager = $this->createGroup('equipment_manager', ['equipment.read', 'equipment.update']);

        $user->assignRole($viewer, $equipmentManager);

        $permissions = app(EffectivePermissionService::class)->forUser($user);

        $this->assertTrue($permissions->allows('customers', null, 'read'));
        $this->assertTrue($permissions->allows('equipment', null, 'read'));
        $this->assertTrue($permissions->allows('equipment', null, 'update'));
        $this->assertFalse($permissions->allows('customers', null, 'update'));
    }

    public function test_effective_permissions_api_returns_resource_and_field_booleans(): void
    {
        $user = User::factory()->create();
        $group = $this->createGroup('customer_sensitive_viewer', [
            'customers.read',
            'customers.field.phone.read',
        ]);

        $user->assignRole($group);
        Sanctum::actingAs($user);

        $this->getJson('/api/permissions/effective')
            ->assertOk()
            ->assertJsonPath('data.resources.customers.actions.read', true)
            ->assertJsonPath('data.resources.customers.actions.create', false)
            ->assertJsonPath('data.resources.customers.actions.update', false)
            ->assertJsonPath('data.resources.customers.actions.delete', false)
            ->assertJsonPath('data.resources.customers.actions.export', false)
            ->assertJsonPath('data.resources.customers.fields.phone.read', true)
            ->assertJsonPath('data.resources.customers.fields.phone.update', false)
            ->assertJsonPath('data.resources.customers.fields.phone.export', false);
    }

    public function test_removing_group_immediately_removes_permissions_after_cache_reset(): void
    {
        $user = User::factory()->create();
        $viewer = $this->createGroup('customer_viewer', ['customers.read']);
        $exporter = $this->createGroup('customer_exporter', ['customers.export']);

        $user->assignRole($viewer, $exporter);

        $this->assertTrue(app(EffectivePermissionService::class)->forUser($user)->allows('customers', null, 'export'));

        $user->removeRole($exporter);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(app(EffectivePermissionService::class)->forUser($user->fresh())->allows('customers', null, 'export'));
    }

    public function test_duplicate_permissions_from_multiple_groups_are_returned_once(): void
    {
        $user = User::factory()->create();
        $viewer = $this->createGroup('customer_viewer', ['customers.read']);
        $auditor = $this->createGroup('customer_auditor', ['customers.read']);

        $user->assignRole($viewer, $auditor);

        $payload = app(EffectivePermissionService::class)->forUser($user)->toArray();

        $this->assertTrue($payload['resources']['customers']['actions']['read']);
        $this->assertSame(['read'], array_keys(array_filter($payload['resources']['customers']['actions'])));
    }

    public function test_super_admin_receives_every_catalog_permission(): void
    {
        $user = User::factory()->create(['email' => 'super_admin@example.test']);
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);

        $user->assignRole($role);

        $permissions = app(EffectivePermissionService::class)->forUser($user);

        $this->assertTrue($permissions->allows('customers', null, 'delete'));
        $this->assertTrue($permissions->allows('equipment', 'serial_no', 'export'));
        $this->assertTrue($permissions->allows('system.users', 'email', 'update'));
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function createGroup(string $name, array $permissionNames): Role
    {
        $permissions = collect($permissionNames)->map(
            fn (string $permissionName): Permission => Permission::findOrCreate($permissionName, 'web')
        );

        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role;
    }
}

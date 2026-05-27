<?php

namespace Tests\Feature\System;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DictionaryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_can_create_dictionary_set_and_items(): void
    {
        $admin = $this->userWithPermissions(['system.dictionaries.read', 'system.dictionaries.create', 'system.dictionaries.update']);

        $setId = $this->postJsonAs($admin, '/api/dictionaries', [
            'code' => 'customer.type',
            'name' => 'Customer Type',
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $this->postJsonAs($admin, "/api/dictionaries/{$setId}/items", [
            'label' => 'Enterprise',
            'value' => 'enterprise',
            'color' => '#2563eb',
            'sort_order' => 10,
            'is_default' => true,
            'status' => 'active',
        ])->assertCreated();

        $this->getJsonAs($admin, '/api/dictionaries')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'customer.type')
            ->assertJsonPath('data.0.items.0.value', 'enterprise');
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_dictionary_admin_'.str()->random(8), 'guard_name' => 'web']);
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

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }
}

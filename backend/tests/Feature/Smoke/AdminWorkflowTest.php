<?php

namespace Tests\Feature\Smoke;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_can_create_group_user_and_run_first_release_workflow(): void
    {
        $admin = $this->superAdmin();

        $groupId = $this->postJsonAs($admin, '/api/system/groups', [
            'name' => 'qa_operator',
            'description' => 'QA operator',
            'status' => 'active',
            'permissions' => [
                'customers.read',
                'customers.create',
                'customers.field.phone.read',
                'customers.field.phone.update',
                'customer_contacts.read',
                'customer_contacts.create',
                'customer_contacts.field.phone.read',
                'customer_contacts.field.phone.update',
                'equipment.read',
                'equipment.create',
                'equipment.field.serial_no.read',
                'equipment.field.serial_no.update',
                'equipment_locations.read',
                'equipment_locations.create',
                'equipment_labels.print',
            ],
        ])->assertCreated()->json('data.id');

        $operatorId = $this->postJsonAs($admin, '/api/system/users', [
            'name' => 'Workflow Operator',
            'email' => 'workflow.operator@example.test',
            'password' => 'password-123',
            'phone' => '13800009999',
            'status' => 'active',
            'group_ids' => [$groupId],
        ])->assertCreated()
            ->assertJsonPath('data.groups.0.id', $groupId)
            ->json('data.id');

        $operator = User::query()->findOrFail($operatorId);

        $permissionResponse = $this->getJsonAs($operator, '/api/permissions/effective')
            ->assertOk()
            ->assertJsonPath('data.resources.customers.actions.create', true)
            ->assertJsonPath('data.resources.customers.fields.phone.update', true);

        $this->assertFalse($permissionResponse->json('data.resources')['system.users']['actions']['create']);

        $customerId = $this->postJsonAs($operator, '/api/customers', [
            'name' => 'Workflow Customer',
            'phone' => '13800008888',
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.phone', '13800008888')
            ->json('data.id');

        $this->postJsonAs($operator, "/api/customers/{$customerId}/contacts", [
            'name' => 'Workflow Contact',
            'phone' => '13800007777',
            'is_default' => true,
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.is_default', true);

        $locationId = $this->postJsonAs($operator, '/api/equipment-locations', [
            'name' => 'Workflow Lab',
            'code' => 'WF-LAB',
            'sort_order' => 1,
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $equipmentId = $this->postJsonAs($operator, '/api/equipment', [
            'equipment_no' => 'WF-EQ-001',
            'name' => 'Workflow Balance',
            'manufacturer' => 'Workflow Inc',
            'model' => 'WF-100',
            'serial_no' => 'WF-SN-001',
            'location_id' => $locationId,
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.serial_no', 'WF-SN-001')
            ->json('data.id');

        $this->postJsonAs($operator, '/api/equipment-labels/preview', [
            'equipment_ids' => [$equipmentId],
            'label_width_mm' => 40,
            'label_height_mm' => 60,
        ])->assertOk()
            ->assertJsonPath('data.0.equipment_no', 'WF-EQ-001')
            ->assertJsonPath('data.0.footer', 'XPD_LIMS')
            ->assertJsonPath('meta.label_width_mm', 40)
            ->assertJsonPath('meta.label_height_mm', 60);

        $viewer = $this->userWithPermissions(['customers.read']);

        $this->getJsonAs($viewer, '/api/customers?search=Workflow Customer')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Workflow Customer')
            ->assertJsonPath('data.0.phone', null)
            ->assertJsonPath('meta.fields.phone.read', false);

        $this->getJsonAs($admin, "/api/audit-logs?actor_user_id={$operatorId}")
            ->assertOk()
            ->assertJsonFragment(['action' => 'customers.create'])
            ->assertJsonFragment(['action' => 'customer_contacts.create'])
            ->assertJsonFragment(['action' => 'equipment_locations.create'])
            ->assertJsonFragment(['action' => 'equipment.create']);
    }

    private function superAdmin(): User
    {
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'system_key' => 'super_admin', 'status' => 'active']);
        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_smoke_'.str()->random(8), 'guard_name' => 'web']);
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

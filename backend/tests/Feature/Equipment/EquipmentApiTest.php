<?php

namespace Tests\Feature\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EquipmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_can_manage_equipment_location_tree_and_cannot_delete_location_with_equipment(): void
    {
        $admin = $this->userWithPermissions([
            'equipment_locations.read',
            'equipment_locations.create',
            'equipment_locations.update',
            'equipment_locations.delete',
            'equipment.create',
        ]);

        $rootId = $this->postJsonAs($admin, '/api/equipment-locations', [
            'name' => 'Main Lab',
            'code' => 'MAIN',
            'sort_order' => 1,
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $childId = $this->postJsonAs($admin, '/api/equipment-locations', [
            'parent_id' => $rootId,
            'name' => 'Room A',
            'code' => 'ROOM-A',
            'sort_order' => 1,
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        Equipment::query()->create([
            'equipment_no' => 'EQ-001',
            'name' => 'Spectrometer',
            'manufacturer' => 'Acme',
            'model' => 'S1',
            'serial_no' => 'SN-001',
            'location_id' => $childId,
            'status' => 'active',
        ]);

        $this->deleteJsonAs($admin, "/api/equipment-locations/{$childId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location']);

        $this->getJsonAs($admin, '/api/equipment-locations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $rootId)
            ->assertJsonPath('data.0.children.0.id', $childId);
    }

    public function test_admin_can_create_update_and_disable_equipment(): void
    {
        $admin = $this->userWithPermissions([
            'equipment.read',
            'equipment.create',
            'equipment.update',
            'equipment.delete',
            'equipment.field.serial_no.read',
            'equipment.field.serial_no.update',
        ]);
        $location = EquipmentLocation::query()->create(['name' => 'Main Lab', 'code' => 'MAIN', 'status' => 'active']);

        $equipmentId = $this->postJsonAs($admin, '/api/equipment', [
            'equipment_no' => 'EQ-100',
            'name' => 'Balance',
            'manufacturer' => 'Scale Inc',
            'model' => 'B100',
            'serial_no' => 'SER-100',
            'location_id' => $location->id,
            'purchase_date' => '2026-01-01',
            'enable_date' => '2026-01-15',
            'calibration_date' => '2026-02-01',
            'calibration_duration' => '12 months',
            'next_calibration_date' => '2027-02-01',
            'status' => 'active',
            'remark' => 'Daily use',
        ])->assertCreated()->json('data.id');

        $this->putJsonAs($admin, "/api/equipment/{$equipmentId}", [
            'name' => 'Balance Updated',
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.name', 'Balance Updated');

        $this->deleteJsonAs($admin, "/api/equipment/{$equipmentId}")->assertOk();

        $this->assertDatabaseHas('equipment', ['id' => $equipmentId, 'status' => 'disabled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'equipment.create', 'subject_id' => (string) $equipmentId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'equipment.update', 'subject_id' => (string) $equipmentId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'equipment.delete', 'subject_id' => (string) $equipmentId]);
    }

    public function test_equipment_list_filters_by_status_location_manufacturer_and_calibration_due_date(): void
    {
        $admin = $this->userWithPermissions(['equipment.read']);
        $location = EquipmentLocation::query()->create(['name' => 'Main Lab', 'code' => 'MAIN', 'status' => 'active']);
        $target = Equipment::query()->create([
            'equipment_no' => 'EQ-FILTER',
            'name' => 'Filtered Balance',
            'manufacturer' => 'Scale Inc',
            'model' => 'B100',
            'location_id' => $location->id,
            'next_calibration_date' => '2026-06-01',
            'status' => 'maintenance',
        ]);
        Equipment::query()->create([
            'equipment_no' => 'EQ-FILTER-OTHER',
            'name' => 'Filtered Other Balance',
            'manufacturer' => 'Other Inc',
            'model' => 'B200',
            'next_calibration_date' => '2027-01-01',
            'status' => 'active',
        ]);

        $this->getJsonAs($admin, "/api/equipment?search=FILTER&status=maintenance&location_id={$location->id}&manufacturer=Scale&calibration_due_to=2026-06-30")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('meta.total', 1);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_equipment_api_'.str()->random(8), 'guard_name' => 'web']);
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

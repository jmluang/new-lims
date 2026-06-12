<?php

namespace Tests\Feature\Equipment;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EquipmentCalibrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_manager_can_create_update_view_and_delete_equipment_calibration(): void
    {
        $manager = $this->userWithPermissions([
            'equipment_calibrations.read',
            'equipment_calibrations.create',
            'equipment_calibrations.update',
            'equipment_calibrations.delete',
        ]);
        $equipment = Equipment::query()->create(['equipment_no' => 'EQ-CAL-001', 'name' => '积分球', 'model' => 'A1', 'status' => 'active']);
        $standard = Equipment::query()->create(['equipment_no' => 'STD-CAL-001', 'name' => '标准灯', 'model' => 'S1', 'status' => 'active']);

        $id = $this->postJsonAs($manager, '/api/equipment-calibrations', [
            'calibration_name' => '积分球定标',
            'calibration_time' => '2026-06-12 09:00:00',
            'result' => 'qualified',
            'devices' => [['equipment_id' => $equipment->id]],
            'standards' => [['equipment_id' => $standard->id]],
        ])->assertCreated()->json('data.id');

        $this->getJsonAs($manager, "/api/equipment-calibrations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.devices.0.equipment_no', 'EQ-CAL-001')
            ->assertJsonPath('data.devices.0.equipment_model', 'A1')
            ->assertJsonPath('data.standards.0.standard_no', 'STD-CAL-001');

        $this->putJsonAs($manager, "/api/equipment-calibrations/{$id}", [
            'calibration_name' => '积分球定标修订',
            'calibration_time' => '2026-06-12 10:00:00',
            'result' => 'unqualified',
            'devices' => [['equipment_id' => $equipment->id, 'remark' => '复检']],
            'standards' => [],
        ])->assertOk()
            ->assertJsonPath('data.result', 'unqualified')
            ->assertJsonPath('data.devices.0.remark', '复检');

        $this->assertDatabaseCount('equipment_calibration_standards', 0);

        $this->getJsonAs($manager, '/api/equipment-calibrations')
            ->assertOk()
            ->assertJsonPath('data.0.devices_count', 1)
            ->assertJsonPath('data.0.standards_count', 0);

        $this->deleteJsonAs($manager, "/api/equipment-calibrations/{$id}")->assertOk();
        $this->assertDatabaseMissing('equipment_calibrations', ['id' => $id]);
        $this->assertDatabaseCount('equipment_calibration_devices', 0);
    }

    public function test_create_requires_permission(): void
    {
        $viewer = $this->userWithPermissions(['equipment_calibrations.read']);

        $this->postJsonAs($viewer, '/api/equipment-calibrations', [
            'calibration_name' => '积分球定标',
            'calibration_time' => '2026-06-12 09:00:00',
        ])->assertForbidden();
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'equipment_calibration_'.str()->random(8), 'guard_name' => 'web']);
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

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }

    private function deleteJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->deleteJson($uri);
    }
}

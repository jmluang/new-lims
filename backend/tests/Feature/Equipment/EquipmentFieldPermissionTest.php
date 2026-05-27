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

class EquipmentFieldPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_equipment_detail_hides_file_fields_without_file_read_permissions(): void
    {
        $manager = $this->userWithPermissions(['equipment.read']);
        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-FILE',
            'name' => 'File Equipment',
            'manufacturer' => 'Acme',
            'model' => 'F1',
            'serial_no' => 'SER-FILE',
            'status' => 'active',
            'device_image' => 'private/device.jpg',
            'manual_files' => ['manual.pdf'],
            'instruction_files' => ['instruction.pdf'],
            'calibration_files' => ['calibration.pdf'],
            'other_files' => ['other.pdf'],
        ]);

        $response = $this->getJsonAs($manager, "/api/equipment/{$equipment->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'File Equipment')
            ->assertJsonPath('data.device_image', null)
            ->assertJsonPath('data.manual_files', null)
            ->assertJsonPath('data.instruction_files', null)
            ->assertJsonPath('data.calibration_files', null)
            ->assertJsonPath('data.other_files', null)
            ->assertJsonPath('meta.fields.device_image.read', false);

        $this->assertStringNotContainsString('private/device.jpg', $response->getContent());
        $this->assertStringNotContainsString('manual.pdf', $response->getContent());
    }

    public function test_equipment_delete_response_hides_file_fields_without_file_read_permissions(): void
    {
        $manager = $this->userWithPermissions(['equipment.delete']);
        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-DELETE-FILE',
            'name' => 'Delete File Equipment',
            'status' => 'active',
            'device_image' => 'private/delete-device.jpg',
            'manual_files' => ['delete-manual.pdf'],
        ]);

        $response = $this->deleteJsonAs($manager, "/api/equipment/{$equipment->id}")
            ->assertOk()
            ->assertJsonPath('data.device_image', null)
            ->assertJsonPath('data.manual_files', null);

        $this->assertStringNotContainsString('private/delete-device.jpg', $response->getContent());
        $this->assertStringNotContainsString('delete-manual.pdf', $response->getContent());
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_equipment_field_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
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

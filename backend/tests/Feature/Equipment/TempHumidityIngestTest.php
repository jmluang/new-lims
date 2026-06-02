<?php

namespace Tests\Feature\Equipment;

use App\Models\Equipment;
use App\Models\TempHumidityRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TempHumidityIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_device_can_post_a_reading_without_authentication(): void
    {
        $this->postJson('/api/device/temp-humidity', [
            'temperature' => 25.6,
            'humidity' => 60.2,
            'equip_no' => 'XPD-S-041',
        ])->assertCreated()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.equip_no', 'XPD-S-041')
            ->assertJsonPath('data.record_person', '设备自动');

        $this->assertDatabaseHas('temp_humidity_records', [
            'equip_no' => 'XPD-S-041',
            'temperature' => 25.60,
            'humidity' => 60.20,
            'record_person' => '设备自动',
        ]);
    }

    public function test_device_can_push_a_reading_by_get_query_string(): void
    {
        $this->getJson('/api/device/temp-humidity?temperature=18.5&humidity=44.1&equip_no=XPD-S-099')
            ->assertCreated()
            ->assertJsonPath('data.equip_no', 'XPD-S-099');

        $this->assertSame(1, TempHumidityRecord::query()->count());
    }

    public function test_ingest_resolves_equipment_name_in_response(): void
    {
        Equipment::query()->create([
            'equipment_no' => 'XPD-S-041',
            'name' => '恒温恒湿箱',
            'status' => 'active',
        ]);

        $this->postJson('/api/device/temp-humidity', [
            'temperature' => 25.6,
            'humidity' => 60.2,
            'equip_no' => 'XPD-S-041',
        ])->assertCreated()
            ->assertJsonPath('data.equipment_name', '恒温恒湿箱');
    }

    public function test_ingest_requires_temperature_humidity_and_equip_no(): void
    {
        $this->postJson('/api/device/temp-humidity', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['temperature', 'humidity', 'equip_no']);
    }

    public function test_user_with_create_permission_can_record_a_reading_manually(): void
    {
        $editor = $this->userWithPermissions(['temp_humidity_records.create']);

        Sanctum::actingAs($editor);

        $this->postJson('/api/temp-humidity-records', [
            'location_site' => '曹一天宏',
            'location_room' => '样品室',
            'equip_no' => 'XPD-S-041',
            'temperature' => 25.3,
            'humidity' => 65.0,
            'record_person' => '张三',
        ])->assertCreated()
            ->assertJsonPath('data.location_site', '曹一天宏')
            ->assertJsonPath('data.record_person', '张三');

        $this->assertDatabaseHas('temp_humidity_records', [
            'location_site' => '曹一天宏',
            'record_person' => '张三',
        ]);
    }

    public function test_manual_create_requires_site_room_and_person(): void
    {
        $editor = $this->userWithPermissions(['temp_humidity_records.create']);

        Sanctum::actingAs($editor);

        $this->postJson('/api/temp-humidity-records', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location_site', 'location_room', 'record_person']);
    }

    public function test_user_with_update_permission_can_edit_a_reading(): void
    {
        $record = TempHumidityRecord::query()->create([
            'location_site' => 'A',
            'location_room' => 'B',
            'equip_no' => 'XPD-S-041',
            'temperature' => 25.6,
            'humidity' => 60.2,
            'record_person' => '张三',
            'record_time' => now(),
        ]);

        $editor = $this->userWithPermissions(['temp_humidity_records.update']);
        Sanctum::actingAs($editor);

        $this->putJson("/api/temp-humidity-records/{$record->id}", [
            'location_site' => 'A2',
            'location_room' => 'B2',
            'equip_no' => 'XPD-S-041',
            'temperature' => 30.0,
            'humidity' => 50.0,
            'record_person' => '李四',
        ])->assertOk()
            ->assertJsonPath('data.location_site', 'A2')
            ->assertJsonPath('data.record_person', '李四');

        $this->assertDatabaseHas('temp_humidity_records', [
            'id' => $record->id,
            'location_site' => 'A2',
            'record_person' => '李四',
        ]);
    }

    public function test_user_with_delete_permission_can_delete_a_reading(): void
    {
        $record = TempHumidityRecord::query()->create([
            'location_site' => 'A',
            'location_room' => 'B',
            'temperature' => 25.6,
            'humidity' => 60.2,
            'record_person' => '张三',
            'record_time' => now(),
        ]);

        $remover = $this->userWithPermissions(['temp_humidity_records.delete']);
        Sanctum::actingAs($remover);

        $this->deleteJson("/api/temp-humidity-records/{$record->id}")->assertOk();

        $this->assertDatabaseMissing('temp_humidity_records', ['id' => $record->id]);
    }

    public function test_manual_write_is_forbidden_without_permission(): void
    {
        $reader = $this->userWithPermissions(['temp_humidity_records.read']);
        Sanctum::actingAs($reader);

        $this->postJson('/api/temp-humidity-records', [
            'location_site' => 'A',
            'location_room' => 'B',
            'record_person' => '张三',
        ])->assertForbidden();
    }

    public function test_index_lists_records_for_permitted_user(): void
    {
        TempHumidityRecord::query()->create([
            'equip_no' => 'XPD-S-041',
            'temperature' => 25.6,
            'humidity' => 60.2,
            'record_person' => '设备自动',
            'record_time' => now(),
        ]);

        $reader = $this->userWithPermissions(['temp_humidity_records.read']);

        Sanctum::actingAs($reader);

        $this->getJson('/api/temp-humidity-records')
            ->assertOk()
            ->assertJsonPath('data.0.equip_no', 'XPD-S-041')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/temp-humidity-records')->assertForbidden();
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_temp_humidity_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

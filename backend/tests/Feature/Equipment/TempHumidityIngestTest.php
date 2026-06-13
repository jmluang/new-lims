<?php

namespace Tests\Feature\Equipment;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentLocation;
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

    public function test_ingest_links_known_equipment_and_uses_equipment_location_defaults(): void
    {
        $site = EquipmentLocation::query()->create(['name' => '曹一天宏', 'code' => 'SITE']);
        $room = EquipmentLocation::query()->create(['parent_id' => $site->id, 'name' => '样品室', 'code' => 'ROOM']);
        $equipment = Equipment::query()->create([
            'equipment_no' => 'XPD-S-041',
            'name' => '恒温恒湿箱',
            'model' => 'A1',
            'location_id' => $room->id,
            'calibration_date' => '2026-01-01',
            'next_calibration_date' => '2027-01-01',
            'status' => 'active',
        ]);

        $this->postJson('/api/device/temp-humidity', [
            'temperature' => 25.6,
            'humidity' => 60.2,
            'equip_no' => 'XPD-S-041',
        ])->assertCreated()
            ->assertJsonPath('data.equipment_id', $equipment->id)
            ->assertJsonPath('data.location_site', '曹一天宏')
            ->assertJsonPath('data.location_room', '样品室')
            ->assertJsonPath('data.equipment.name', '恒温恒湿箱')
            ->assertJsonPath('data.equipment.next_calibration_date', '2027-01-01');

        $this->assertDatabaseHas('temp_humidity_records', [
            'equip_no' => 'XPD-S-041',
            'equipment_id' => $equipment->id,
            'location_site' => '曹一天宏',
            'location_room' => '样品室',
        ]);
    }

    public function test_ingest_preserves_unknown_legacy_equipment_code(): void
    {
        $this->postJson('/api/device/temp-humidity', [
            'temperature' => 18.5,
            'humidity' => 44.1,
            'equip_no' => 'UNKNOWN-SENSOR',
        ])->assertCreated()
            ->assertJsonPath('data.equip_no', 'UNKNOWN-SENSOR')
            ->assertJsonPath('data.equipment_id', null)
            ->assertJsonPath('data.location_site', '曹一天宏')
            ->assertJsonPath('data.location_room', '样品室');

        $this->assertDatabaseHas('temp_humidity_records', [
            'equip_no' => 'UNKNOWN-SENSOR',
            'equipment_id' => null,
            'location_site' => '曹一天宏',
            'location_room' => '样品室',
        ]);
    }

    public function test_get_ingest_keeps_legacy_method_compatibility_for_known_equipment(): void
    {
        $site = EquipmentLocation::query()->create(['name' => '曹一天宏', 'code' => 'SITE']);
        $room = EquipmentLocation::query()->create(['parent_id' => $site->id, 'name' => '样品室', 'code' => 'ROOM']);
        $equipment = Equipment::query()->create([
            'equipment_no' => 'XPD-S-043',
            'name' => '温湿度记录仪',
            'location_id' => $room->id,
            'status' => 'active',
        ]);

        $this->getJson('/api/device/temp-humidity?temperature=19.5&humidity=43.2&equip_no=XPD-S-043')
            ->assertCreated()
            ->assertJsonPath('data.equipment_id', $equipment->id)
            ->assertJsonPath('data.location_site', '曹一天宏')
            ->assertJsonPath('data.location_room', '样品室');
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

    public function test_manual_write_audit_log_includes_canonical_equipment_link(): void
    {
        $equipment = Equipment::query()->create([
            'equipment_no' => 'XPD-S-041',
            'name' => '恒温恒湿箱',
            'status' => 'active',
        ]);
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
            ->assertJsonPath('data.equipment_id', $equipment->id);

        $audit = AuditLog::query()
            ->where('action', 'temp_humidity_records.create')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($equipment->id, $audit->after_values['equipment_id']);
    }

    public function test_manual_update_audit_log_tracks_canonical_equipment_link_changes(): void
    {
        $equipment = Equipment::query()->create([
            'equipment_no' => 'XPD-S-041',
            'name' => '恒温恒湿箱',
            'status' => 'active',
        ]);
        $record = TempHumidityRecord::query()->create([
            'location_site' => 'A',
            'location_room' => 'B',
            'temperature' => 25.6,
            'humidity' => 60.2,
            'record_person' => '张三',
            'record_time' => now(),
        ]);

        $editor = $this->userWithPermissions(['temp_humidity_records.update']);
        Sanctum::actingAs($editor);

        $this->putJson("/api/temp-humidity-records/{$record->id}", [
            'equip_no' => 'XPD-S-041',
        ])->assertOk()
            ->assertJsonPath('data.equipment_id', $equipment->id);

        $audit = AuditLog::query()
            ->where('action', 'temp_humidity_records.update')
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($audit->before_values['equipment_id']);
        $this->assertSame($equipment->id, $audit->after_values['equipment_id']);
        $this->assertSame(['old' => null, 'new' => $equipment->id], $audit->changed_values['equipment_id']);
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

    public function test_update_preserves_existing_room_when_equipment_has_top_level_location_without_legacy_room(): void
    {
        $site = EquipmentLocation::query()->create(['name' => '曹一天宏', 'code' => 'SITE']);
        Equipment::query()->create([
            'equipment_no' => 'XPD-S-044',
            'name' => '温湿度记录仪',
            'location_id' => $site->id,
            'legacy_placement' => null,
            'status' => 'active',
        ]);
        $record = TempHumidityRecord::query()->create([
            'equip_no' => 'OLD',
            'temperature' => 20.0,
            'humidity' => 50.0,
            'location_site' => '旧场所',
            'location_room' => '原房间',
            'record_person' => 'Alice',
            'record_time' => now(),
        ]);

        $operator = $this->userWithPermissions(['temp_humidity_records.update']);
        Sanctum::actingAs($operator);

        $this->putJson("/api/temp-humidity-records/{$record->id}", [
            'equip_no' => 'XPD-S-044',
        ])->assertOk()
            ->assertJsonPath('data.location_site', '曹一天宏')
            ->assertJsonPath('data.location_room', '原房间');
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

    public function test_user_can_lookup_equipment_for_temperature_humidity_form(): void
    {
        $site = EquipmentLocation::query()->create(['name' => '曹一天宏', 'code' => 'SITE']);
        $room = EquipmentLocation::query()->create(['parent_id' => $site->id, 'name' => '样品室', 'code' => 'ROOM']);
        Equipment::query()->create([
            'equipment_no' => 'XPD-S-041',
            'name' => '恒温恒湿箱',
            'model' => 'A1',
            'location_id' => $room->id,
            'calibration_date' => '2026-01-01',
            'next_calibration_date' => '2027-01-01',
            'status' => 'active',
        ]);

        $operator = $this->userWithPermissions(['temp_humidity_records.create']);
        Sanctum::actingAs($operator);

        $this->getJson('/api/temp-humidity-records/equipment-lookup?equip_no=XPD-S-041')
            ->assertOk()
            ->assertJsonPath('data.equipment_no', 'XPD-S-041')
            ->assertJsonPath('data.name', '恒温恒湿箱')
            ->assertJsonPath('data.location_site', '曹一天宏')
            ->assertJsonPath('data.location_room', '样品室')
            ->assertJsonPath('data.next_calibration_date', '2027-01-01');
    }

    public function test_lookup_returns_404_for_unknown_equipment(): void
    {
        $operator = $this->userWithPermissions(['temp_humidity_records.create']);
        Sanctum::actingAs($operator);

        $this->getJson('/api/temp-humidity-records/equipment-lookup?equip_no=NOPE')
            ->assertNotFound();
    }

    public function test_update_only_user_can_lookup_equipment_for_temperature_humidity_form(): void
    {
        Equipment::query()->create([
            'equipment_no' => 'XPD-S-042',
            'name' => '温湿度记录仪',
            'status' => 'active',
        ]);

        $operator = $this->userWithPermissions(['temp_humidity_records.update']);
        Sanctum::actingAs($operator);

        $this->getJson('/api/temp-humidity-records/equipment-lookup?equip_no=XPD-S-042')
            ->assertOk()
            ->assertJsonPath('data.equipment_no', 'XPD-S-042');
    }

    public function test_index_filters_by_time_and_temperature_ranges(): void
    {
        TempHumidityRecord::query()->create([
            'equip_no' => 'A',
            'temperature' => 20.0,
            'humidity' => 40.0,
            'location_site' => '曹一天宏',
            'location_room' => '样品室',
            'record_person' => 'Alice',
            'record_time' => '2026-06-01 08:00:00',
        ]);
        TempHumidityRecord::query()->create([
            'equip_no' => 'B',
            'temperature' => 30.0,
            'humidity' => 70.0,
            'location_site' => '其他场所',
            'location_room' => '仓库',
            'record_person' => 'Bob',
            'record_time' => '2026-06-10 08:00:00',
        ]);

        $reader = $this->userWithPermissions(['temp_humidity_records.read']);
        Sanctum::actingAs($reader);

        $this->getJson('/api/temp-humidity-records?record_time_from=2026-06-02&temperature_min=25&humidity_max=80')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.equip_no', 'B');
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

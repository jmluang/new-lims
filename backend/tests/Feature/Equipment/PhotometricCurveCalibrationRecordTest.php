<?php

namespace Tests\Feature\Equipment;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentSystem;
use App\Models\PhotometricCurveCalibrationRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PhotometricCurveCalibrationRecordTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/photometric-curve-calibration-records';

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('inspection_media');
    }

    public function test_endpoints_are_denied_without_permissions_and_record_the_denial(): void
    {
        $stranger = $this->userWithPermissions([]);
        $record = $this->record($this->equipment('STD-DENY'), [$this->equipment('EQ-DENY')]);
        $media = $this->attachPhoto($record);

        $this->getJsonAs($stranger, self::BASE)->assertForbidden();
        $this->getJsonAs($stranger, self::BASE."/{$record->id}")->assertForbidden();
        $this->getJsonAs($stranger, self::BASE.'/equipment')->assertForbidden();
        $this->getJsonAs($stranger, self::BASE.'/lookup?type=standard&code=STD-DENY')->assertForbidden();
        $this->getJsonAs($stranger, self::BASE."/{$record->id}/media/{$media->id}/view")->assertForbidden();
        $this->getJsonAs($stranger, self::BASE."/{$record->id}/media/{$media->id}/download")->assertForbidden();
        $this->postJsonAs($stranger, self::BASE, [])->assertForbidden();
        $this->putJsonAs($stranger, self::BASE."/{$record->id}", [])->assertForbidden();
        $this->deleteJsonAs($stranger, self::BASE."/{$record->id}")->assertForbidden();

        $this->assertSame(
            9,
            AuditLog::query()
                ->where('action', 'authorization.denied')
                ->where('module', 'photometric_curve_calibration_records')
                ->count(),
        );
    }

    public function test_lookup_resolves_equipment_standard_and_system_without_ledger_read_permissions(): void
    {
        $operator = $this->userWithPermissions(['photometric_curve_calibration_records.create']);
        $equipment = $this->equipment('XPD-S-001');
        $standard = $this->equipment('XPD-L-030', name: '标准灯');
        $system = $this->system('sys-01');

        $this->getJsonAs($operator, '/api/equipment')->assertForbidden();
        $this->getJsonAs($operator, self::BASE)->assertForbidden();

        $this->getJsonAs($operator, self::BASE.'/lookup?type=equipment&code=XPD-S-001')
            ->assertOk()
            ->assertJsonPath('data.id', $equipment->id)
            ->assertJsonPath('data.equipment_no', 'XPD-S-001');

        $this->getJsonAs($operator, self::BASE.'/lookup?type=standard&code=XPD-L-030')
            ->assertOk()
            ->assertJsonPath('data.id', $standard->id)
            ->assertJsonPath('data.equipment_no', 'XPD-L-030')
            ->assertJsonPath('data.equipment_name', '标准灯');

        $this->getJsonAs($operator, self::BASE.'/lookup?type=system&code=sys-01')
            ->assertOk()
            ->assertJsonPath('data.id', $system->id)
            ->assertJsonPath('data.code', 'sys-01');
    }

    public function test_creating_a_record_snapshots_standard_system_devices_and_preserves_every_scale(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030', name: '标准灯', manufacturer: 'OSRAM', model: 'NAV-T 400W');
        $device1 = $this->equipment('XPD-S-001');
        $device2 = $this->equipment('XPD-S-002', name: '光度计');
        $system = $this->system('sys-01');

        $response = $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device1->id, $device2->id],
            ...$this->measurements(),
            'remark' => '定标测试',
        ])->assertCreated();

        $response
            ->assertJsonPath('data.standard_equipment_id', $standard->id)
            ->assertJsonPath('data.standard_no', 'XPD-L-030')
            ->assertJsonPath('data.standard_name', '标准灯')
            ->assertJsonPath('data.standard_manufacturer', 'OSRAM')
            ->assertJsonPath('data.standard_model', 'NAV-T 400W')
            ->assertJsonPath('data.equipment_system_id', $system->id)
            ->assertJsonPath('data.system_code', 'sys-01')
            ->assertJsonPath('data.system_name', '系统1')
            ->assertJsonPath('data.probe', 'far_field')
            ->assertJsonPath('data.test_distance', '26.2314')
            ->assertJsonPath('data.calibration_coefficient', '1.0024')
            ->assertJsonPath('data.peak_luminous_intensity', '221.0')
            ->assertJsonPath('data.luminous_flux', '1674.0')
            ->assertJsonPath('data.voltage', '220.80')
            ->assertJsonPath('data.current', '0.1189')
            ->assertJsonPath('data.power', '14.2400')
            ->assertJsonPath('data.power_factor', '0.5422')
            ->assertJsonPath('data.frequency', 50)
            ->assertJsonPath('data.remark', '定标测试')
            ->assertJsonPath('data.operator_id', $operator->id)
            ->assertJsonPath('data.operator_name', $operator->name)
            ->assertJsonCount(2, 'data.equipment')
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.equipment.1.equipment_no', 'XPD-S-002');

        $this->assertDatabaseHas('photometric_curve_calibration_records', [
            'id' => $response->json('data.id'),
            'probe' => 'far_field',
            'test_distance' => '26.2314',
            'calibration_coefficient' => '1.0024',
            'peak_luminous_intensity' => '221.0',
            'power_factor' => '0.5422',
            'frequency' => 50,
        ]);
    }

    public function test_creation_requires_a_standard_an_active_system_one_device_and_a_valid_probe(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        $this->postJsonAs($operator, self::BASE, $this->measurements())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['standard_equipment_id', 'equipment_system_id', 'equipment_ids']);

        $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [],
            ...$this->measurements(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['equipment_ids']);

        $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(probe: 'side_field'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['probe']);

        // Duplicated device ids would produce two snapshots of one device.
        $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id, $device->id],
            ...$this->measurements(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['equipment_ids.0']);

        // A disabled system cannot answer a fresh selection.
        $disabled = $this->system('sys-off', status: 'disabled');
        $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $disabled->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['equipment_system_id']);
    }

    public function test_snapshots_are_immutable_and_not_live_joins(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030', name: '标准灯');
        $device = $this->equipment('XPD-S-001', name: '智能电源');
        $system = $this->system('sys-01', name: '系统1');

        $record = $this->record($standard, [$device], $system);

        $standard->update(['equipment_no' => 'XPD-L-999', 'name' => '改名标准灯']);
        $device->update(['equipment_no' => 'XPD-S-999', 'name' => '改名电源']);
        $system->update(['code' => 'sys-99', 'name' => '改名系统']);

        $this->getJsonAs($operator, self::BASE."/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.standard_no', 'XPD-L-030')
            ->assertJsonPath('data.standard_name', '标准灯')
            ->assertJsonPath('data.system_code', 'sys-01')
            ->assertJsonPath('data.system_name', '系统1')
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.equipment.0.equipment_name', '智能电源');
    }

    public function test_standard_lifecycle_retained_replaced_and_orphaned(): void
    {
        $operator = $this->editor();
        $std1 = $this->equipment('XPD-L-001', name: '标准灯1', manufacturer: 'OSRAM', model: 'NAV-T', serialNo: 'SN1', nextCalibration: '2027-01-01');
        $std2 = $this->equipment('XPD-L-002', name: '标准件无参数', manufacturer: null, model: null, serialNo: null, nextCalibration: null);
        $device = $this->equipment('XPD-S-001');

        $record = $this->record($std1, [$device]);

        // Omitting standard_equipment_id retains the stored snapshot.
        $this->putJsonAs($operator, self::BASE."/{$record->id}", $this->measurements())
            ->assertOk()
            ->assertJsonPath('data.standard_equipment_id', $std1->id)
            ->assertJsonPath('data.standard_no', 'XPD-L-001')
            ->assertJsonPath('data.standard_manufacturer', 'OSRAM');

        // An explicit replacement rewrites the whole snapshot, nulls included.
        $this->putJsonAs($operator, self::BASE."/{$record->id}", [
            'standard_equipment_id' => $std2->id,
            ...$this->measurements(),
        ])
            ->assertOk()
            ->assertJsonPath('data.standard_equipment_id', $std2->id)
            ->assertJsonPath('data.standard_no', 'XPD-L-002')
            ->assertJsonPath('data.standard_name', '标准件无参数')
            ->assertJsonPath('data.standard_manufacturer', null)
            ->assertJsonPath('data.standard_model', null)
            ->assertJsonPath('data.standard_serial_no', null)
            ->assertJsonPath('data.standard_next_calibration_date', null);

        // Deleting the ledger row keeps the historical evidence.
        $std2->delete();

        $this->getJsonAs($operator, self::BASE."/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.standard_equipment_id', null)
            ->assertJsonPath('data.standard_no', 'XPD-L-002')
            ->assertJsonPath('data.standard_name', '标准件无参数');
    }

    public function test_system_lifecycle_retained_replaced_and_orphaned(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030');
        $device = $this->equipment('XPD-S-001');
        $sys1 = $this->system('sys-01', name: '系统1');
        $sys2 = $this->system('sys-02', name: '系统2');

        $record = $this->record($standard, [$device], $sys1);

        $this->putJsonAs($operator, self::BASE."/{$record->id}", $this->measurements())
            ->assertOk()
            ->assertJsonPath('data.equipment_system_id', $sys1->id)
            ->assertJsonPath('data.system_code', 'sys-01');

        $this->putJsonAs($operator, self::BASE."/{$record->id}", [
            'equipment_system_id' => $sys2->id,
            ...$this->measurements(),
        ])
            ->assertOk()
            ->assertJsonPath('data.equipment_system_id', $sys2->id)
            ->assertJsonPath('data.system_code', 'sys-02')
            ->assertJsonPath('data.system_name', '系统2');

        $sys2->delete();

        $this->getJsonAs($operator, self::BASE."/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.equipment_system_id', null)
            ->assertJsonPath('data.system_code', 'sys-02');
    }

    public function test_used_equipment_retention_replacement_and_orphaned_snapshots(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030');
        $eq1 = $this->equipment('XPD-S-001');
        $eq2 = $this->equipment('XPD-S-002');
        $eq3 = $this->equipment('XPD-S-003');

        $record = $this->record($standard, [$eq1, $eq2]);
        $child1 = $record->equipment()->where('equipment_id', $eq1->id)->firstOrFail();

        $this->putJsonAs($operator, self::BASE."/{$record->id}", [
            'retained_equipment_ids' => [$child1->id],
            'equipment_ids' => [$eq3->id],
            ...$this->measurements(),
        ])->assertOk();

        $record = $record->fresh(['equipment']);
        $this->assertCount(2, $record->equipment);
        $this->assertEqualsCanonicalizing([$eq1->id, $eq3->id], $record->equipment->pluck('equipment_id')->all());

        $eq1->delete();

        $this->getJsonAs($operator, self::BASE."/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.equipment.0.equipment_id', null)
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001');
    }

    public function test_a_child_snapshot_or_media_id_from_another_record_is_rejected(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030');
        $device = $this->equipment('XPD-S-001');

        $record = $this->record($standard, [$device]);
        $other = $this->record($standard, [$this->equipment('XPD-S-002')], $this->system('sys-other'));
        $otherChild = $other->equipment()->firstOrFail();
        $otherMedia = $this->attachPhoto($other);

        $this->putJsonAs($operator, self::BASE."/{$record->id}", [
            'retained_equipment_ids' => [$otherChild->id],
            ...$this->measurements(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['retained_equipment_ids']);

        $this->putJsonAs($operator, self::BASE."/{$record->id}", [
            'retained_media_ids' => [$otherMedia->id],
            ...$this->measurements(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['retained_media_ids']);

        // The record still carries its own evidence untouched.
        $this->assertSame(1, $record->fresh()->equipment()->count());
    }

    public function test_decimal_scales_are_preserved_exactly_and_invalid_notations_are_rejected(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        $create = fn (array $overrides) => $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(...$overrides),
        ]);

        // Trailing zeros the operator typed survive the round trip at every scale.
        $create(['testDistance' => '0.1000', 'calibrationCoefficient' => '1.0000', 'peakLuminousIntensity' => '0.0'])
            ->assertCreated()
            ->assertJsonPath('data.test_distance', '0.1000')
            ->assertJsonPath('data.calibration_coefficient', '1.0000')
            ->assertJsonPath('data.peak_luminous_intensity', '0.0');

        // Scientific notation is numeric to PHP but is not a scale this API stores.
        $create(['testDistance' => '1e-5'])->assertUnprocessable()->assertJsonValidationErrors(['test_distance']);
        $create(['current' => '1e-2'])->assertUnprocessable()->assertJsonValidationErrors(['current']);

        // A JSON number reaches PHP as a float, which has already lost the scale the
        // record is supposed to preserve, so only canonical strings are accepted. These
        // bypass the typed helper, which would coerce a float back into a valid string.
        $createRaw = fn (array $raw) => $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(),
            ...$raw,
        ]);

        $createRaw(['current' => 0.1189])->assertUnprocessable()->assertJsonValidationErrors(['current']);
        $createRaw(['calibration_coefficient' => 1.0024])->assertUnprocessable()->assertJsonValidationErrors(['calibration_coefficient']);
        $createRaw(['peak_luminous_intensity' => 221.0])->assertUnprocessable()->assertJsonValidationErrors(['peak_luminous_intensity']);

        // Numeric to PHP, but not a spelling this API should be storing.
        $create(['power' => '.5'])->assertUnprocessable()->assertJsonValidationErrors(['power']);
        $create(['power' => '14.'])->assertUnprocessable()->assertJsonValidationErrors(['power']);

        // Excess scale is refused rather than silently rounded.
        $create(['calibrationCoefficient' => '1.00245'])->assertUnprocessable()->assertJsonValidationErrors(['calibration_coefficient']);
        $create(['peakLuminousIntensity' => '221.05'])->assertUnprocessable()->assertJsonValidationErrors(['peak_luminous_intensity']);
        $create(['voltage' => '220.855'])->assertUnprocessable()->assertJsonValidationErrors(['voltage']);

        // Negatives are physically invalid for every column of this form.
        $create(['testDistance' => '-1.0000'])->assertUnprocessable()->assertJsonValidationErrors(['test_distance']);
        $create(['current' => '-0.1189'])->assertUnprocessable()->assertJsonValidationErrors(['current']);
        $create(['luminousFlux' => '-1674.0'])->assertUnprocessable()->assertJsonValidationErrors(['luminous_flux']);

        // Overflowing the column precision fails validation, not the database write.
        $create(['power' => '100000000.0000'])->assertUnprocessable()->assertJsonValidationErrors(['power']);
        $create(['luminousFlux' => '100000000000.0'])->assertUnprocessable()->assertJsonValidationErrors(['luminous_flux']);
        $create(['voltage' => '100000000.0'])->assertUnprocessable()->assertJsonValidationErrors(['voltage']);
    }

    public function test_power_factor_is_limited_to_zero_through_one_and_frequency_is_an_integer(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        $create = fn (array $overrides) => $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(...$overrides),
        ]);

        $create(['powerFactor' => '1.0000'])->assertCreated()->assertJsonPath('data.power_factor', '1.0000');
        $create(['powerFactor' => '1.0001'])->assertUnprocessable()->assertJsonValidationErrors(['power_factor']);
        $create(['powerFactor' => '-0.1'])->assertUnprocessable()->assertJsonValidationErrors(['power_factor']);

        $create(['frequency' => '50.5'])->assertUnprocessable()->assertJsonValidationErrors(['frequency']);
        $create(['frequency' => -1])->assertUnprocessable()->assertJsonValidationErrors(['frequency']);
        $create(['frequency' => 1000001])->assertUnprocessable()->assertJsonValidationErrors(['frequency']);
    }

    public function test_remark_is_optional_trimmed_and_bounded(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        $create = fn (array $extra) => $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(),
            ...$extra,
        ]);

        $create([])->assertCreated()->assertJsonPath('data.remark', null);
        $create(['remark' => '   '])->assertCreated()->assertJsonPath('data.remark', null);
        $create(['remark' => '  两侧空白  '])->assertCreated()->assertJsonPath('data.remark', '两侧空白');
        $create(['remark' => str_repeat('a', 2000)])->assertCreated();
        $create(['remark' => str_repeat('a', 2001)])->assertUnprocessable()->assertJsonValidationErrors(['remark']);
    }

    public function test_server_owned_timestamp_and_operator_ignore_client_payload(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');
        $forged = User::factory()->create(['name' => 'Forged Operator']);

        $response = $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(),
            'recorded_at' => '2000-01-01 00:00:00',
            'operator_id' => $forged->id,
            'operator_name' => 'Forged Operator',
        ])->assertCreated();

        $response
            ->assertJsonPath('data.operator_id', $operator->id)
            ->assertJsonPath('data.operator_name', $operator->name);

        $recordedAt = $response->json('data.recorded_at');
        $recordId = $response->json('data.id');
        $this->assertNotSame('2000-01-01 00:00:00', $recordedAt);

        // Editing only the coefficient keeps the evidence, the time and the identity.
        $this->putJsonAs($operator, self::BASE."/{$recordId}", [
            ...$this->measurements(calibrationCoefficient: '1.0030'),
            'recorded_at' => '1999-12-31 23:59:59',
            'operator_id' => $forged->id,
            'operator_name' => 'Forged Operator',
        ])
            ->assertOk()
            ->assertJsonPath('data.calibration_coefficient', '1.0030')
            ->assertJsonPath('data.recorded_at', $recordedAt)
            ->assertJsonPath('data.operator_id', $operator->id)
            ->assertJsonPath('data.operator_name', $operator->name)
            ->assertJsonPath('data.standard_no', 'XPD-L-030')
            ->assertJsonPath('data.system_code', 'sys-01')
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001');
    }

    public function test_list_search_probe_and_date_filters_pagination_and_ordering(): void
    {
        $user = $this->userWithPermissions(['photometric_curve_calibration_records.read']);
        $std1 = $this->equipment('XPD-L-001', name: '标准灯1');
        $std2 = $this->equipment('XPD-L-002', name: '标准灯2');
        $eq1 = $this->equipment('XPD-S-001', name: '电源1');
        $eq2 = $this->equipment('XPD-S-002', name: '光度计2');

        $older = $this->record($std1, [$eq1], $this->system('sys-filter-1'), probe: 'near_field', recordedAt: '2026-08-01 09:00:00');
        $newer = $this->record($std2, [$eq2], $this->system('sys-filter-2'), probe: 'far_field', recordedAt: '2026-08-20 09:00:00');

        // Newest first.
        $this->getJsonAs($user, self::BASE)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);

        $this->getJsonAs($user, self::BASE.'?search=XPD-L-001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $older->id);

        // Search reaches the used-equipment snapshots too.
        $this->getJsonAs($user, self::BASE.'?search='.urlencode('光度计2'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id);

        $this->getJsonAs($user, self::BASE.'?search=sys-filter-2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id);

        $this->getJsonAs($user, self::BASE.'?probe=near_field')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $older->id);

        $this->getJsonAs($user, self::BASE.'?date_from=2026-08-10&date_to=2026-08-25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id);

        $this->getJsonAs($user, self::BASE.'?per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_global_equipment_ledger_exposes_one_row_per_used_device_snapshot(): void
    {
        $user = $this->userWithPermissions(['photometric_curve_calibration_records.read']);
        $standard = $this->equipment('XPD-L-030');
        $eq1 = $this->equipment('XPD-S-001');
        $eq2 = $this->equipment('XPD-S-002');

        $record = $this->record($standard, [$eq1, $eq2], recordedAt: '2026-08-20 08:00:00');

        $response = $this->getJsonAs($user, self::BASE.'/equipment')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.calibration_record_id', $record->id)
            ->assertJsonPath('data.1.calibration_record_id', $record->id)
            ->assertJsonPath('data.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.0.recorded_at', '2026-08-20 08:00:00')
            ->assertJsonPath('data.0.operator_name', 'Operator');

        $this->assertArrayNotHasKey('inspection_record_id', $response->json('data.0'));

        $this->getJsonAs($user, self::BASE.'/equipment?search=XPD-S-002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.equipment_no', 'XPD-S-002');

        $this->getJsonAs($user, self::BASE."/equipment?calibration_record_id={$record->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_equipment_ledger_filters_only_on_its_own_parent_key(): void
    {
        $user = $this->userWithPermissions(['photometric_curve_calibration_records.read']);
        $standard = $this->equipment('XPD-L-030');

        $first = $this->record($standard, [$this->equipment('XPD-S-001')], $this->system('sys-a'));
        $second = $this->record($standard, [$this->equipment('XPD-S-002')], $this->system('sys-b'));

        // The workflow's own key selects exactly its own children.
        $this->getJsonAs($user, self::BASE."/equipment?calibration_record_id={$first->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.calibration_record_id', $first->id)
            ->assertJsonPath('data.0.equipment_no', 'XPD-S-001');

        // A sibling workflow's parameter is not this ledger's contract: it is ignored
        // rather than silently filtering on the calibration foreign key.
        $this->getJsonAs($user, self::BASE."/equipment?inspection_record_id={$first->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Combining the two leaves only the calibration key in force.
        $this->getJsonAs($user, self::BASE."/equipment?calibration_record_id={$second->id}&inspection_record_id={$first->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.calibration_record_id', $second->id);
    }

    public function test_media_type_count_size_ownership_retention_and_private_delivery(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        $photo = UploadedFile::fake()->image('calibration_photo.jpg');
        $doc = UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $response = $this->postAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(),
            'photos' => [$photo],
            'files' => [$doc],
        ])->assertCreated();

        $recordId = $response->json('data.id');
        $photoId = $response->json('data.photos.0.id');
        $fileId = $response->json('data.files.0.id');

        // No storage path or public URL crosses the API.
        $this->assertArrayNotHasKey('path', $response->json('data.photos.0'));
        $this->assertArrayNotHasKey('url', $response->json('data.photos.0'));

        $this->getJsonAs($operator, self::BASE."/{$recordId}/media/{$photoId}/view")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->getJsonAs($operator, self::BASE."/{$recordId}/media/{$fileId}/download")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=report.pdf');

        // A document is not viewable through the photo endpoint.
        $this->getJsonAs($operator, self::BASE."/{$recordId}/media/{$fileId}/view")->assertNotFound();

        // Media of another record cannot be reached through this record.
        $other = $this->record($standard, [$device], $this->system('sys-other'));
        $this->getJsonAs($operator, self::BASE."/{$other->id}/media/{$photoId}/view")->assertNotFound();
        $this->getJsonAs($operator, self::BASE."/{$other->id}/media/{$fileId}/download")->assertNotFound();

        // A measurement-only edit names no media at all, so both attachments stay.
        $this->putJsonAs($operator, self::BASE."/{$recordId}", $this->measurements(calibrationCoefficient: '1.0030'))
            ->assertOk()
            ->assertJsonPath('data.calibration_coefficient', '1.0030')
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonCount(1, 'data.files')
            ->assertJsonPath('data.photos.0.id', $photoId)
            ->assertJsonPath('data.files.0.id', $fileId);

        // Retaining only the photo removes the document.
        $this->postAs($operator, self::BASE."/{$recordId}", [
            '_method' => 'PUT',
            'retained_media_ids' => [$photoId],
            ...$this->measurements(),
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonCount(0, 'data.files');

        // A rejected type never reaches storage.
        $this->postAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(),
            'photos' => [UploadedFile::fake()->createWithContent('evil.jpg', '<?php echo 1;')],
        ])->assertUnprocessable();

        $this->postAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(),
            'photos' => array_map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 11)),
        ])->assertUnprocessable()->assertJsonValidationErrors(['photos']);

        $this->postAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(),
            'photos' => [UploadedFile::fake()->image('big.jpg')->size(10241)],
        ])->assertUnprocessable()->assertJsonValidationErrors(['photos.0']);
    }

    public function test_audit_entries_carry_metadata_without_private_paths_or_contents(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-030');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');
        $doc = UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $created = $this->postAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            ...$this->measurements(),
            'files' => [$doc],
        ])->assertCreated();

        $recordId = $created->json('data.id');
        $fileId = $created->json('data.files.0.id');

        $this->getJsonAs($operator, self::BASE."/{$recordId}/media/{$fileId}/download")->assertOk();
        $this->putJsonAs($operator, self::BASE."/{$recordId}", ['remark' => '更新备注', ...$this->measurements()])->assertOk();
        $this->deleteJsonAs($operator, self::BASE."/{$recordId}")->assertOk();

        foreach (['create', 'media.download', 'update', 'delete'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => "photometric_curve_calibration_records.{$action}"]);
        }

        $download = AuditLog::query()->where('action', 'photometric_curve_calibration_records.media.download')->firstOrFail();
        $payload = json_encode($download->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('test.pdf', $payload);
        $this->assertStringNotContainsString('%PDF-1.4', $payload);
        $this->assertStringNotContainsString('inspection_media/', $payload);

        $this->assertDatabaseMissing('photometric_curve_calibration_records', ['id' => $recordId]);
        $this->assertDatabaseMissing('photometric_curve_calibration_equipment', ['calibration_record_id' => $recordId]);
    }

    public function test_delete_is_rejected_for_a_role_without_the_delete_action(): void
    {
        $author = $this->userWithPermissions([
            'photometric_curve_calibration_records.read',
            'photometric_curve_calibration_records.create',
            'photometric_curve_calibration_records.update',
        ]);
        $record = $this->record($this->equipment('XPD-L-030'), [$this->equipment('XPD-S-001')]);

        $this->getJsonAs($author, self::BASE."/{$record->id}")->assertOk();
        $this->putJsonAs($author, self::BASE."/{$record->id}", $this->measurements())->assertOk();
        $this->deleteJsonAs($author, self::BASE."/{$record->id}")->assertForbidden();

        $this->assertDatabaseHas('photometric_curve_calibration_records', ['id' => $record->id]);
    }

    private function editor(): User
    {
        return $this->userWithPermissions([
            'photometric_curve_calibration_records.read',
            'photometric_curve_calibration_records.create',
            'photometric_curve_calibration_records.update',
            'photometric_curve_calibration_records.delete',
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'role-'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function equipment(
        string $code,
        string $name = '智能电源',
        ?string $manufacturer = '杭州远方',
        ?string $model = 'DPS1060',
        ?string $serialNo = 'SN123456',
        ?string $nextCalibration = '2027-03-01',
    ): Equipment {
        return Equipment::query()->create([
            'equipment_no' => $code,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'model' => $model,
            'serial_no' => $serialNo,
            'next_calibration_date' => $nextCalibration,
            'status' => 'active',
        ]);
    }

    private function system(string $code = 'sys-01', string $name = '系统1', string $status = 'active'): EquipmentSystem
    {
        return EquipmentSystem::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'status' => $status],
        );
    }

    /**
     * @param  array<int, Equipment>  $equipmentList
     */
    private function record(
        Equipment $standard,
        array $equipmentList,
        ?EquipmentSystem $system = null,
        string $probe = 'far_field',
        string $recordedAt = '2026-08-21 10:00:00',
    ): PhotometricCurveCalibrationRecord {
        $system ??= $this->system();

        $record = PhotometricCurveCalibrationRecord::query()->create([
            'standard_equipment_id' => $standard->id,
            'standard_no' => $standard->equipment_no,
            'standard_name' => $standard->name,
            'standard_manufacturer' => $standard->manufacturer,
            'standard_model' => $standard->model,
            'standard_serial_no' => $standard->serial_no,
            'standard_next_calibration_date' => $standard->next_calibration_date,
            'equipment_system_id' => $system->id,
            'system_code' => $system->code,
            'system_name' => $system->name,
            'probe' => $probe,
            ...$this->measurements(probe: $probe),
            'recorded_at' => $recordedAt,
            'operator_name' => 'Operator',
        ]);

        foreach ($equipmentList as $device) {
            $record->equipment()->create([
                'equipment_id' => $device->id,
                'equipment_no' => $device->equipment_no,
                'equipment_name' => $device->name,
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'serial_no' => $device->serial_no,
                'next_calibration_date' => $device->next_calibration_date,
            ]);
        }

        return $record;
    }

    /**
     * The representative dataset from the workbook, with every value spelled at the
     * exact scale the form promises.
     *
     * @return array<string, mixed>
     */
    private function measurements(
        string $probe = 'far_field',
        string $testDistance = '26.2314',
        string $calibrationCoefficient = '1.0024',
        string $peakLuminousIntensity = '221.0',
        string $luminousFlux = '1674.0',
        string $voltage = '220.80',
        string $current = '0.1189',
        string $power = '14.2400',
        string $powerFactor = '0.5422',
        int|string $frequency = 50,
    ): array {
        return [
            'probe' => $probe,
            'test_distance' => $testDistance,
            'calibration_coefficient' => $calibrationCoefficient,
            'peak_luminous_intensity' => $peakLuminousIntensity,
            'luminous_flux' => $luminousFlux,
            'voltage' => $voltage,
            'current' => $current,
            'power' => $power,
            'power_factor' => $powerFactor,
            'frequency' => $frequency,
        ];
    }

    private function attachPhoto(PhotometricCurveCalibrationRecord $record): Media
    {
        $file = UploadedFile::fake()->image('test_photo.jpg');

        return $record->addMedia($file)
            ->usingName('test_photo')
            ->withCustomProperties([
                'original_file_name' => 'test_photo.jpg',
                'mime_type' => 'image/jpeg',
                'size' => $file->getSize(),
            ])
            ->toMediaCollection(PhotometricCurveCalibrationRecord::PHOTO_COLLECTION);
    }

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postJsonAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->postJson($uri, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->post($uri, $data, ['Accept' => 'application/json']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
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
}

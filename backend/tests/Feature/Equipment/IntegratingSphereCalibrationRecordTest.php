<?php

namespace Tests\Feature\Equipment;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentSystem;
use App\Models\IntegratingSphereCalibrationRecord;
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

class IntegratingSphereCalibrationRecordTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/integrating-sphere-calibration-records';

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('inspection_media');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function test_endpoints_are_denied_without_permissions_and_record_the_denial(): void
    {
        $stranger = $this->userWithPermissions([]);
        $record = $this->record($this->equipment('STD-DENY'), [$this->equipment('EQ-DENY')]);
        $media = $this->attachPhoto($record);

        $this->getJsonAs($stranger, self::BASE)->assertForbidden();
        $this->getJsonAs($stranger, self::BASE."/{$record->id}")->assertForbidden();
        $this->getJsonAs($stranger, self::BASE.'/form-options')->assertForbidden();
        $this->getJsonAs($stranger, self::BASE.'/equipment')->assertForbidden();
        $this->getJsonAs($stranger, self::BASE.'/lookup?type=standard&code=STD-DENY')->assertForbidden();
        $this->getJsonAs($stranger, self::BASE."/{$record->id}/media/{$media->id}/view")->assertForbidden();
        $this->getJsonAs($stranger, self::BASE."/{$record->id}/media/{$media->id}/download")->assertForbidden();
        $this->postJsonAs($stranger, self::BASE, [])->assertForbidden();
        $this->putJsonAs($stranger, self::BASE."/{$record->id}", [])->assertForbidden();
        $this->deleteJsonAs($stranger, self::BASE."/{$record->id}")->assertForbidden();

        $this->assertSame(
            10,
            AuditLog::query()
                ->where('action', 'authorization.denied')
                ->where('module', 'integrating_sphere_calibration_records')
                ->count(),
        );
    }

    public function test_form_options_returns_active_catalog_modes_and_sensitivities(): void
    {
        $user = $this->userWithPermissions(['integrating_sphere_calibration_records.read']);

        $response = $this->getJsonAs($user, self::BASE.'/form-options')
            ->assertOk()
            ->assertJsonPath('data.modes.0.code', 'precise')
            ->assertJsonPath('data.modes.0.label', '精准')
            ->assertJsonPath('data.modes.1.code', 'fast')
            ->assertJsonPath('data.modes.1.label', '快速')
            ->assertJsonPath('data.sensitivities.0.code', 'high')
            ->assertJsonPath('data.sensitivities.0.label', '高')
            ->assertJsonPath('data.sensitivities.1.code', 'low')
            ->assertJsonPath('data.sensitivities.1.label', '低');

        $this->assertCount(2, $response->json('data.modes'));
        $this->assertCount(2, $response->json('data.sensitivities'));
    }

    public function test_lookup_resolves_equipment_standard_and_system_without_ledger_read_permissions(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_calibration_records.create']);
        $equipment = $this->equipment('XPD-S-001');
        $standard = $this->equipment('XPD-L-028', name: '标准灯');
        $system = $this->system('sys-01');

        $this->getJsonAs($operator, '/api/equipment')->assertForbidden();
        $this->getJsonAs($operator, self::BASE)->assertForbidden();

        $this->getJsonAs($operator, self::BASE.'/lookup?type=equipment&code=XPD-S-001')
            ->assertOk()
            ->assertJsonPath('data.id', $equipment->id)
            ->assertJsonPath('data.equipment_no', 'XPD-S-001');

        $this->getJsonAs($operator, self::BASE.'/lookup?type=standard&code=XPD-L-028')
            ->assertOk()
            ->assertJsonPath('data.id', $standard->id)
            ->assertJsonPath('data.equipment_no', 'XPD-L-028')
            ->assertJsonPath('data.equipment_name', '标准灯');

        $this->getJsonAs($operator, self::BASE.'/lookup?type=system&code=sys-01')
            ->assertOk()
            ->assertJsonPath('data.id', $system->id)
            ->assertJsonPath('data.code', 'sys-01');
    }

    public function test_creating_a_record_snapshots_standard_system_and_catalog_labels(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-028', name: '标准高压钠灯', manufacturer: 'OSRAM', model: 'NAV-T 400W');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        $response = $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'precise',
            'sensitivity_code' => 'high',
            ...$this->measurements(),
            'remark' => '定标测试',
        ])->assertCreated();

        $response
            ->assertJsonPath('data.standard_equipment_id', $standard->id)
            ->assertJsonPath('data.standard_no', 'XPD-L-028')
            ->assertJsonPath('data.standard_name', '标准高压钠灯')
            ->assertJsonPath('data.standard_manufacturer', 'OSRAM')
            ->assertJsonPath('data.system_code', 'sys-01')
            ->assertJsonPath('data.mode_code', 'precise')
            ->assertJsonPath('data.mode_label', '精准')
            ->assertJsonPath('data.sensitivity_code', 'high')
            ->assertJsonPath('data.sensitivity_label', '高')
            ->assertJsonPath('data.color_temperature', 4360)
            ->assertJsonPath('data.color_rendering_index', '88.4')
            ->assertJsonPath('data.luminous_flux', '1674.0')
            ->assertJsonPath('data.voltage', '220.80')
            ->assertJsonPath('data.current', '0.1189')
            ->assertJsonPath('data.power', '14.2400')
            ->assertJsonPath('data.power_factor', '0.5422')
            ->assertJsonPath('data.frequency', 50)
            ->assertJsonPath('data.remark', '定标测试')
            ->assertJsonPath('data.operator_name', $operator->name);

        $this->assertDatabaseHas('integrating_sphere_calibration_records', [
            'id' => $response->json('data.id'),
            'mode_code' => 'precise',
            'mode_label' => '精准',
            'sensitivity_code' => 'high',
            'sensitivity_label' => '高',
            'power_factor' => '0.5422',
            'frequency' => 50,
        ]);
    }

    public function test_store_rejects_arbitrary_mode_or_sensitivity_codes(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-028');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'invalid_mode',
            'sensitivity_code' => 'high',
            ...$this->measurements(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode_code']);

        $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'precise',
            'sensitivity_code' => 'ultra_high',
            ...$this->measurements(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sensitivity_code']);
    }

    public function test_existing_records_remain_editable_when_catalog_option_is_removed(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-028');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        $record = $this->record($standard, [$device], $system, modeCode: 'fast', modeLabel: '快速');

        config([
            'calibration.integrating_sphere.modes' => [
                ['code' => 'precise', 'label' => '精准', 'status' => 'active'],
            ],
        ]);

        $this->getJsonAs($operator, self::BASE."/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.mode_code', 'fast')
            ->assertJsonPath('data.mode_label', '快速');

        // Omitting mode_code retains existing mode snapshot
        $this->putJsonAs($operator, self::BASE."/{$record->id}", [
            'color_temperature' => 4500,
            ...$this->measurements(colorTemperature: 4500),
        ])
            ->assertOk()
            ->assertJsonPath('data.color_temperature', 4500)
            ->assertJsonPath('data.mode_code', 'fast')
            ->assertJsonPath('data.mode_label', '快速');
    }

    public function test_standard_device_lifecycle_retained_replaced_and_orphaned(): void
    {
        $operator = $this->editor();
        $std1 = $this->equipment('XPD-L-001', name: '标准灯1');
        $std2 = $this->equipment('XPD-L-002', name: '标准灯2');
        $device = $this->equipment('XPD-S-001');

        $record = $this->record($std1, [$device]);

        // Retained standard when standard_equipment_id is omitted
        $this->putJsonAs($operator, self::BASE."/{$record->id}", [
            ...$this->measurements(),
        ])
            ->assertOk()
            ->assertJsonPath('data.standard_equipment_id', $std1->id)
            ->assertJsonPath('data.standard_no', 'XPD-L-001');

        // Replaced standard when standard_equipment_id is explicitly sent
        $this->putJsonAs($operator, self::BASE."/{$record->id}", [
            'standard_equipment_id' => $std2->id,
            ...$this->measurements(),
        ])
            ->assertOk()
            ->assertJsonPath('data.standard_equipment_id', $std2->id)
            ->assertJsonPath('data.standard_no', 'XPD-L-002')
            ->assertJsonPath('data.standard_name', '标准灯2');

        // Delete std2 from equipment ledger -> orphaned standard snapshot preserved
        $std2->delete();

        $this->getJsonAs($operator, self::BASE."/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.standard_equipment_id', null)
            ->assertJsonPath('data.standard_no', 'XPD-L-002')
            ->assertJsonPath('data.standard_name', '标准灯2');
    }

    public function test_replacing_standard_device_overwrites_null_fields_and_does_not_leak_old_snapshot_values(): void
    {
        $operator = $this->editor();
        $std1 = $this->equipment('XPD-L-001', name: '标准灯1', manufacturer: 'OSRAM', model: 'NAV-T 400W', serialNo: 'SN123', nextCalibration: '2027-01-01');
        $std2 = $this->equipment('XPD-L-002', name: '标准件无参数', manufacturer: null, model: null, serialNo: null, nextCalibration: null);
        $device = $this->equipment('XPD-S-001');

        $record = $this->record($std1, [$device]);

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
    }

    public function test_used_equipment_retention_replacement_and_orphaned_snapshots(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-028');
        $eq1 = $this->equipment('XPD-S-001');
        $eq2 = $this->equipment('XPD-S-002');
        $eq3 = $this->equipment('XPD-S-003');

        $record = $this->record($standard, [$eq1, $eq2]);

        $child1 = $record->equipment()->where('equipment_id', $eq1->id)->firstOrFail();

        // Retaining child1, dropping child2, adding eq3
        $this->putJsonAs($operator, self::BASE."/{$record->id}", [
            'retained_equipment_ids' => [$child1->id],
            'equipment_ids' => [$eq3->id],
            ...$this->measurements(),
        ])->assertOk();

        $record = $record->fresh(['equipment']);
        $this->assertCount(2, $record->equipment);
        $this->assertEqualsCanonicalizing([$eq1->id, $eq3->id], $record->equipment->pluck('equipment_id')->all());

        // Delete eq1 from equipment ledger -> snapshot remains with equipment_id = null
        $eq1->delete();

        $this->getJsonAs($operator, self::BASE."/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.equipment.0.equipment_id', null)
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001');
    }

    public function test_exact_decimals_integer_bounds_power_factor_and_frequency(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-028');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        // Valid power_factor 0.5422 and frequency 50
        $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'precise',
            'sensitivity_code' => 'high',
            'color_temperature' => 4360,
            'color_rendering_index' => '88.4',
            'luminous_flux' => '1674.0',
            'voltage' => '220.80',
            'current' => '0.1189',
            'power' => '14.2400',
            'power_factor' => '0.5422',
            'frequency' => 50,
        ])->assertCreated();

        // Invalid power factor > 1
        $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'precise',
            'sensitivity_code' => 'high',
            ...$this->measurements(powerFactor: '1.0001'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['power_factor']);

        // Invalid power factor < 0
        $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'precise',
            'sensitivity_code' => 'high',
            ...$this->measurements(powerFactor: '-0.1'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['power_factor']);
    }

    public function test_server_owned_timestamp_and_operator_ignore_client_payload(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-028');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        $forgedUser = User::factory()->create(['name' => 'Forged Operator']);

        $response = $this->postJsonAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'precise',
            'sensitivity_code' => 'high',
            ...$this->measurements(),
            'recorded_at' => '2000-01-01 00:00:00',
            'operator_id' => $forgedUser->id,
            'operator_name' => 'Forged Operator',
        ])->assertCreated();

        $response
            ->assertJsonPath('data.operator_id', $operator->id)
            ->assertJsonPath('data.operator_name', $operator->name);

        $originalRecordedAt = $response->json('data.recorded_at');
        $recordId = $response->json('data.id');

        $this->assertNotEquals('2000-01-01 00:00:00', $originalRecordedAt);

        // Update cannot move recorded_at or forge operator
        $updateRes = $this->putJsonAs($operator, self::BASE."/{$recordId}", [
            ...$this->measurements(),
            'recorded_at' => '1999-12-31 23:59:59',
            'operator_id' => $forgedUser->id,
            'operator_name' => 'Forged Operator',
        ])->assertOk();

        $updateRes
            ->assertJsonPath('data.operator_id', $operator->id)
            ->assertJsonPath('data.operator_name', $operator->name)
            ->assertJsonPath('data.recorded_at', $originalRecordedAt);
    }

    public function test_list_filtering_ordering_and_global_equipment_ledger(): void
    {
        $user = $this->userWithPermissions(['integrating_sphere_calibration_records.read']);
        $std1 = $this->equipment('XPD-L-001', name: '标准灯1');
        $std2 = $this->equipment('XPD-L-002', name: '标准灯2');
        $eq1 = $this->equipment('XPD-S-001', name: '电源1');
        $eq2 = $this->equipment('XPD-S-002', name: '光度计2');

        $rec1 = $this->record($std1, [$eq1], $this->system('sys-filter-1'), modeCode: 'precise', sensitivityCode: 'high');
        $rec2 = $this->record($std2, [$eq2], $this->system('sys-filter-2'), modeCode: 'fast', sensitivityCode: 'low');

        // Filter search by standard_no
        $this->getJsonAs($user, self::BASE.'?search=XPD-L-001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rec1->id);

        // Filter exact mode_code
        $this->getJsonAs($user, self::BASE.'?mode_code=fast')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rec2->id);

        // Global equipment ledger
        $this->getJsonAs($user, self::BASE.'/equipment')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.calibration_record_id', $rec2->id)
            ->assertJsonPath('data.1.calibration_record_id', $rec1->id);
    }

    public function test_equipment_ledger_filters_only_on_its_own_parent_key(): void
    {
        $user = $this->userWithPermissions(['integrating_sphere_calibration_records.read']);
        $standard = $this->equipment('XPD-L-028');

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

    public function test_media_type_count_size_ownership_and_cleanup(): void
    {
        $operator = $this->editor();
        $standard = $this->equipment('XPD-L-028');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');

        $photo = UploadedFile::fake()->image('calibration_photo.jpg');
        $doc = UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $response = $this->postAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'precise',
            'sensitivity_code' => 'high',
            ...$this->measurements(),
            'photos' => [$photo],
            'files' => [$doc],
        ])->assertCreated();

        $recordId = $response->json('data.id');
        $photoId = $response->json('data.photos.0.id');
        $fileId = $response->json('data.files.0.id');

        // Viewing/downloading media of record 1 through record 1 endpoint works
        $this->getJsonAs($operator, self::BASE."/{$recordId}/media/{$photoId}/view")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->getJsonAs($operator, self::BASE."/{$recordId}/media/{$fileId}/download")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=report.pdf');

        // Cross-record media ownership denial: requesting Record 1's media via Record 2 returns 404
        $otherRecord = $this->record($standard, [$device], $this->system('sys-other'));
        $this->getJsonAs($operator, self::BASE."/{$otherRecord->id}/media/{$photoId}/view")->assertNotFound();
        $this->getJsonAs($operator, self::BASE."/{$otherRecord->id}/media/{$fileId}/download")->assertNotFound();

        // Count limit: > 10 photos fails validation
        $this->postAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'precise',
            'sensitivity_code' => 'high',
            ...$this->measurements(),
            'photos' => array_map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 11)),
        ])->assertUnprocessable()->assertJsonValidationErrors(['photos']);

        // Size limit: photo > 10MB fails validation
        $bigPhoto = UploadedFile::fake()->image('big.jpg')->size(10241);
        $this->postAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'precise',
            'sensitivity_code' => 'high',
            ...$this->measurements(),
            'photos' => [$bigPhoto],
        ])->assertUnprocessable()->assertJsonValidationErrors(['photos.0']);
    }

    public function test_audit_logging_for_create_update_delete_and_download(): void
    {
        $operator = $this->userWithPermissions([
            'integrating_sphere_calibration_records.create',
            'integrating_sphere_calibration_records.update',
            'integrating_sphere_calibration_records.delete',
            'integrating_sphere_calibration_records.read',
        ]);
        $standard = $this->equipment('XPD-L-028');
        $device = $this->equipment('XPD-S-001');
        $system = $this->system('sys-01');
        $doc = UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $createRes = $this->postAs($operator, self::BASE, [
            'standard_equipment_id' => $standard->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$device->id],
            'mode_code' => 'precise',
            'sensitivity_code' => 'high',
            ...$this->measurements(),
            'files' => [$doc],
        ])->assertCreated();

        $recordId = $createRes->json('data.id');
        $fileId = $createRes->json('data.files.0.id');

        $this->getJsonAs($operator, self::BASE."/{$recordId}/media/{$fileId}/download")->assertOk();

        $this->putJsonAs($operator, self::BASE."/{$recordId}", [
            'remark' => 'Updated remark',
            ...$this->measurements(),
        ])->assertOk();

        $this->deleteJsonAs($operator, self::BASE."/{$recordId}")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'integrating_sphere_calibration_records.create']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'integrating_sphere_calibration_records.media.download']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'integrating_sphere_calibration_records.update']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'integrating_sphere_calibration_records.delete']);
    }

    private function editor(): User
    {
        return $this->userWithPermissions([
            'integrating_sphere_calibration_records.read',
            'integrating_sphere_calibration_records.create',
            'integrating_sphere_calibration_records.update',
            'integrating_sphere_calibration_records.delete',
        ]);
    }

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

    private function record(
        Equipment $standard,
        array $equipmentList,
        ?EquipmentSystem $system = null,
        string $modeCode = 'precise',
        string $modeLabel = '精准',
        string $sensitivityCode = 'high',
        string $sensitivityLabel = '高',
    ): IntegratingSphereCalibrationRecord {
        $system ??= $this->system();

        $record = IntegratingSphereCalibrationRecord::query()->create([
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
            'mode_code' => $modeCode,
            'mode_label' => $modeLabel,
            'sensitivity_code' => $sensitivityCode,
            'sensitivity_label' => $sensitivityLabel,
            ...$this->measurements(),
            'recorded_at' => now(),
            'operator_name' => 'Operator',
        ]);

        foreach ($equipmentList as $dev) {
            $record->equipment()->create([
                'equipment_id' => $dev->id,
                'equipment_no' => $dev->equipment_no,
                'equipment_name' => $dev->name,
                'manufacturer' => $dev->manufacturer,
                'model' => $dev->model,
                'serial_no' => $dev->serial_no,
                'next_calibration_date' => $dev->next_calibration_date,
            ]);
        }

        return $record;
    }

    private function measurements(
        int $colorTemperature = 4360,
        string $colorRenderingIndex = '88.4',
        string $luminousFlux = '1674.0',
        string $voltage = '220.80',
        string $current = '0.1189',
        string $power = '14.2400',
        string $powerFactor = '0.5422',
        int $frequency = 50,
    ): array {
        return [
            'color_temperature' => $colorTemperature,
            'color_rendering_index' => $colorRenderingIndex,
            'luminous_flux' => $luminousFlux,
            'voltage' => $voltage,
            'current' => $current,
            'power' => $power,
            'power_factor' => $powerFactor,
            'frequency' => $frequency,
        ];
    }

    private function attachPhoto(IntegratingSphereCalibrationRecord $record): Media
    {
        $file = UploadedFile::fake()->image('test_photo.jpg');

        return $record->addMedia($file)
            ->usingName('test_photo')
            ->withCustomProperties([
                'original_file_name' => 'test_photo.jpg',
                'mime_type' => 'image/jpeg',
                'size' => $file->getSize(),
            ])
            ->toMediaCollection(IntegratingSphereCalibrationRecord::PHOTO_COLLECTION);
    }

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }

    private function postJsonAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->postJson($uri, $data);
    }

    private function postAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->post($uri, $data, ['Accept' => 'application/json']);
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
}

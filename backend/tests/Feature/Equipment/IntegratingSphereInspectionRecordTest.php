<?php

namespace Tests\Feature\Equipment;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentSystem;
use App\Models\IntegratingSphereInspectionEquipment;
use App\Models\IntegratingSphereInspectionRecord;
use App\Models\Sample;
use App\Models\TestOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IntegratingSphereInspectionRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_endpoints_are_denied_without_permissions_and_record_the_denial(): void
    {
        $stranger = $this->userWithPermissions([]);
        $record = $this->record($this->sample('S-DENY'), [$this->equipment('EQ-DENY')]);

        $this->getJsonAs($stranger, '/api/integrating-sphere-inspection-records')->assertForbidden();
        $this->getJsonAs($stranger, "/api/integrating-sphere-inspection-records/{$record->id}")->assertForbidden();
        $this->getJsonAs($stranger, '/api/integrating-sphere-inspection-records/lookup?type=equipment&code=EQ-DENY')->assertForbidden();
        $this->postJsonAs($stranger, '/api/integrating-sphere-inspection-records', [])->assertForbidden();
        $this->putJsonAs($stranger, "/api/integrating-sphere-inspection-records/{$record->id}", [])->assertForbidden();
        $this->deleteJsonAs($stranger, "/api/integrating-sphere-inspection-records/{$record->id}")->assertForbidden();

        $this->assertSame(
            6,
            AuditLog::query()
                ->where('action', 'authorization.denied')
                ->where('module', 'integrating_sphere_inspection_records')
                ->count(),
        );
    }

    public function test_lookup_resolves_equipment_and_samples_without_ledger_read_permissions(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('26010058874-1-1/1');

        $this->getJsonAs($operator, '/api/equipment')->assertForbidden();
        $this->getJsonAs($operator, '/api/samples')->assertForbidden();

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/lookup?type=equipment&code=XPD-S-001')
            ->assertOk()
            ->assertJsonPath('data.id', $equipment->id)
            ->assertJsonPath('data.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.equipment_name', '积分球')
            ->assertJsonPath('data.manufacturer', '杭州远方')
            ->assertJsonPath('data.model', 'HAAS-2000')
            ->assertJsonPath('data.serial_no', 'SN-XPD-001')
            ->assertJsonPath('data.next_calibration_date', '2027-03-01');

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/lookup?type=sample&code=26010058874-1-1/1')
            ->assertOk()
            ->assertJsonPath('data.id', $sample->id)
            ->assertJsonPath('data.sample_no', '26010058874-1-1/1')
            ->assertJsonPath('data.sample_name', '灯具');

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/lookup?type=equipment&code=NOPE')
            ->assertNotFound();
    }

    public function test_lookup_is_available_to_editors_without_create_permission(): void
    {
        $editor = $this->userWithPermissions(['integrating_sphere_inspection_records.update']);
        $this->equipment('XPD-S-EDIT');

        $this->getJsonAs($editor, '/api/integrating-sphere-inspection-records/lookup?type=equipment&code=XPD-S-EDIT')
            ->assertOk()
            ->assertJsonPath('data.equipment_no', 'XPD-S-EDIT');
    }

    public function test_creating_a_record_snapshots_the_sample_and_every_device(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create', 'integrating_sphere_inspection_records.read']);
        $first = $this->equipment('XPD-S-001');
        $second = $this->equipment('XPD-S-002', name: '光谱仪', serialNo: 'SN-XPD-002');
        $sample = $this->sample('26010058874-1-1/1');
        $system = $this->system('sys-01');

        $response = $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$first->id, $second->id],
            'recorded_at' => '2026-08-20 12:27:00',
            ...$this->measurements(),
        ])->assertCreated();

        $response
            ->assertJsonPath('data.sample_no', '26010058874-1-1/1')
            ->assertJsonPath('data.chromaticity_x', '0.3633')
            ->assertJsonPath('data.chromaticity_y', '0.3549')
            ->assertJsonPath('data.dominant_wavelength', '580.5')
            ->assertJsonPath('data.peak_wavelength', '601.2')
            ->assertJsonPath('data.color_temperature', 4360)
            ->assertJsonPath('data.color_rendering_index', '88.4')
            ->assertJsonPath('data.luminous_flux', '1234.5')
            ->assertJsonPath('data.voltage', '220.0')
            ->assertJsonPath('data.current', '0.0451')
            ->assertJsonPath('data.power', '9.8765')
            ->assertJsonPath('data.power_factor', '0.9876')
            ->assertJsonPath('data.frequency', 50)
            ->assertJsonPath('data.remark', '首件点检')
            ->assertJsonPath('data.recorded_at', '2026-08-20 12:27:00')
            ->assertJsonPath('data.operator_id', $operator->id)
            ->assertJsonPath('data.operator_name', $operator->name)
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.equipment.0.equipment_name', '积分球')
            ->assertJsonPath('data.equipment.0.manufacturer', '杭州远方')
            ->assertJsonPath('data.equipment.0.model', 'HAAS-2000')
            ->assertJsonPath('data.equipment.0.serial_no', 'SN-XPD-001')
            ->assertJsonPath('data.equipment.0.next_calibration_date', '2027-03-01')
            ->assertJsonPath('data.equipment.1.equipment_no', 'XPD-S-002')
            ->assertJsonPath('data.equipment.1.equipment_name', '光谱仪')
            ->assertJsonCount(2, 'data.equipment');

        $this->assertDatabaseHas('integrating_sphere_inspection_records', [
            'sample_id' => $sample->id,
            'sample_no' => '26010058874-1-1/1',
            'operator_id' => $operator->id,
        ]);
        $this->assertDatabaseCount('integrating_sphere_inspection_equipment', 2);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integrating_sphere_inspection_records.create',
            'module' => 'integrating_sphere_inspection_records',
        ]);
    }

    public function test_recorded_at_and_operator_default_to_now_and_the_authenticated_user(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-DEFAULT');

        $response = $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [
            'sample_id' => $sample->id,
            'equipment_system_id' => $this->system()->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ])->assertCreated();

        $this->assertNotEmpty($response->json('data.recorded_at'));
        $this->assertSame($operator->name, $response->json('data.operator_name'));
        $this->assertSame(
            now()->format('Y-m-d'),
            substr((string) $response->json('data.recorded_at'), 0, 10),
        );
    }

    public function test_creation_rejects_invalid_precision_unknown_ids_and_empty_equipment(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-VALIDATION');
        $base = [
            'sample_id' => $sample->id,
            'equipment_system_id' => $this->system()->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ];

        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'chromaticity_x' => '0.36335'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('chromaticity_x');
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'dominant_wavelength' => '580.55'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dominant_wavelength');
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'color_temperature' => '4360.5'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('color_temperature');
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'frequency' => '50.5'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('frequency');
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'equipment_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('equipment_ids');
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'equipment_ids' => [$equipment->id, $equipment->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('equipment_ids.0');
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'equipment_ids' => [$equipment->id + 999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('equipment_ids.0');
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'sample_id' => $sample->id + 999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sample_id');

        $incomplete = $base;
        unset($incomplete['luminous_flux'], $incomplete['power_factor']);
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', $incomplete)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['luminous_flux', 'power_factor']);

        $this->assertDatabaseCount('integrating_sphere_inspection_records', 0);
    }

    public function test_decimal_measurements_must_be_sent_as_strings(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-STRING');
        $base = [
            'sample_id' => $sample->id,
            'equipment_system_id' => $this->system()->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ];

        // A JSON number would reach PHP as a float, so the exact decimal the operator
        // typed could never be guaranteed; the contract only accepts decimal strings.
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'chromaticity_x' => 0.3633])
            ->assertStatus(422)
            ->assertJsonValidationErrors('chromaticity_x');
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'power_factor' => 0.9876])
            ->assertStatus(422)
            ->assertJsonValidationErrors('power_factor');

        // Integers stay ordinary JSON numbers.
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'color_temperature' => 4360, 'frequency' => 50])
            ->assertCreated();
    }

    public function test_measurements_reject_notations_that_hide_their_real_scale(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-NOTATION');
        $base = [
            'sample_id' => $sample->id,
            'equipment_system_id' => $this->system()->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ];

        // `1e-5` reads as zero decimals to the scale rule while actually being a fifth
        // decimal place, and `1.`/`.5` are numeric to PHP without being a form this API
        // should store. Only plain fixed-point digits are accepted.
        foreach (['1e-5', '1E-5', '0.5e1', '1.', '.5', '+0.3633', '', 'abc', '0.36 33'] as $value) {
            $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'chromaticity_x' => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('chromaticity_x');
        }

        $this->assertDatabaseCount('integrating_sphere_inspection_records', 0);

        // Whitespace is trimmed by the framework before validation, and an unpadded
        // integer is a legitimate spelling of a decimal measurement.
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'chromaticity_x' => ' 0.3633 '])
            ->assertCreated()
            ->assertJsonPath('data.chromaticity_x', '0.3633');
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'chromaticity_x' => '7'])
            ->assertCreated()
            ->assertJsonPath('data.chromaticity_x', '7.0000');
    }

    public function test_measurements_at_the_column_limits_round_trip_exactly(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create', 'integrating_sphere_inspection_records.read']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-LIMITS');
        $limits = [
            'chromaticity_x' => '99.9999',
            'chromaticity_y' => '0.0001',
            'dominant_wavelength' => '999999.9',
            'peak_wavelength' => '0.1',
            'color_temperature' => 1000000,
            'color_rendering_index' => '-9999.9',
            'luminous_flux' => '99999999999.9',
            'voltage' => '99999999.9',
            'current' => '99999999.9999',
            'power' => '0.0001',
            'power_factor' => '99.9999',
            'frequency' => 1000000,
        ];

        $response = $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [
            'sample_id' => $sample->id,
            'equipment_system_id' => $this->system()->id,
            'equipment_ids' => [$equipment->id],
            ...$limits,
        ])->assertCreated();

        foreach ($limits as $field => $value) {
            $this->assertSame($value, $response->json("data.{$field}"), "measurement {$field} lost precision");
        }

        $recordId = $response->json('data.id');

        foreach ($limits as $field => $value) {
            $this->assertSame(
                $value,
                $this->getJsonAs($operator, "/api/integrating-sphere-inspection-records/{$recordId}")->json("data.{$field}"),
                "measurement {$field} lost precision after reload",
            );
        }
    }

    public function test_measurements_beyond_the_column_limits_are_rejected(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-OVERFLOW');
        $base = [
            'sample_id' => $sample->id,
            'equipment_system_id' => $this->system()->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ];

        foreach ([
            'chromaticity_x' => '100.0000',
            'dominant_wavelength' => '1000000.0',
            'color_rendering_index' => '-10000.0',
            'luminous_flux' => '999999999999.9',
            'voltage' => '100000000.0',
            'current' => '100000000.0000',
            'power_factor' => '100.0000',
        ] as $field => $value) {
            $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, $field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        foreach (['color_temperature' => 1000001, 'frequency' => -1] as $field => $value) {
            $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, $field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('integrating_sphere_inspection_records', 0);
    }

    public function test_index_lists_newest_first_and_filters_by_search_and_date(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.read']);
        $older = $this->record($this->sample('S-OLD'), [$this->equipment('XPD-S-OLD')], '2026-08-18 09:00:00');
        $newer = $this->record($this->sample('S-NEW'), [$this->equipment('XPD-S-NEW')], '2026-08-20 09:00:00');

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.equipment.0.equipment_no', 'XPD-S-NEW');

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records?search=S-OLD')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sample_no', 'S-OLD');

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records?search=XPD-S-NEW')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sample_no', 'S-NEW');

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records?date_from=2026-08-19&date_to=2026-08-20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id);
    }

    public function test_global_equipment_ledger_requires_the_record_read_permission(): void
    {
        $stranger = $this->userWithPermissions(['integrating_sphere_inspection_records.create']);

        $this->getJsonAs($stranger, '/api/integrating-sphere-inspection-records/equipment')->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'authorization.denied',
            'module' => 'integrating_sphere_inspection_records',
        ]);
    }

    public function test_global_equipment_ledger_flattens_children_with_their_parent_date_and_operator(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.read']);
        $first = $this->equipment('XPD-S-001');
        $second = $this->equipment('XPD-S-002', name: '光谱仪', serialNo: 'SN-XPD-002');
        $older = $this->record($this->sample('S-LEDGER-OLD'), [$first], '2026-08-18 09:00:00');
        $newer = $this->record($this->sample('S-LEDGER-NEW'), [$first, $second], '2026-08-20 12:27:00');
        [$newerFirstChild, $newerSecondChild] = $newer->equipment->pluck('id')->all();

        $response = $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/equipment?per_page=10')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);

        // Newest parent first, and a record keeps its devices in the order they were added.
        $this->assertSame(
            [$newerFirstChild, $newerSecondChild, $older->equipment->first()->id],
            collect($response->json('data'))->pluck('id')->all(),
        );

        $response
            ->assertJsonPath('data.0.id', $newerFirstChild)
            ->assertJsonPath('data.0.inspection_record_id', $newer->id)
            ->assertJsonPath('data.0.equipment_id', $first->id)
            ->assertJsonPath('data.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.0.equipment_name', '积分球')
            ->assertJsonPath('data.0.manufacturer', '杭州远方')
            ->assertJsonPath('data.0.model', 'HAAS-2000')
            ->assertJsonPath('data.0.serial_no', 'SN-XPD-001')
            ->assertJsonPath('data.0.next_calibration_date', '2027-03-01')
            ->assertJsonPath('data.0.recorded_at', '2026-08-20 12:27:00')
            ->assertJsonPath('data.0.operator_name', '点检员')
            ->assertJsonPath('data.1.equipment_no', 'XPD-S-002')
            ->assertJsonPath('data.1.inspection_record_id', $newer->id)
            ->assertJsonPath('data.2.inspection_record_id', $older->id)
            ->assertJsonPath('data.2.recorded_at', '2026-08-18 09:00:00');

        // Two associations of one record share the parent id but not the child ids.
        $this->assertSame($newer->id, $response->json('data.1.inspection_record_id'));
        $this->assertNotSame($response->json('data.0.id'), $response->json('data.1.id'));
        $this->assertNotSame($response->json('data.0.equipment_id'), $response->json('data.1.equipment_id'));
    }

    public function test_global_equipment_ledger_filters_by_search_ids_and_date_range(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.read']);
        $first = $this->equipment('XPD-S-001');
        $second = $this->equipment(
            'XPD-S-002',
            name: '光谱仪',
            serialNo: 'SN-XPD-002',
            manufacturer: '虹昌电子',
            model: 'SPC-100',
        );
        $older = $this->record($this->sample('S-FILTER-OLD'), [$first], '2026-08-18 09:00:00');
        $newer = $this->record($this->sample('S-FILTER-NEW'), [$second], '2026-08-20 12:27:00');
        $ledger = '/api/integrating-sphere-inspection-records/equipment';

        // One term per searchable snapshot column, including the Chinese ones.
        foreach (['XPD-S-002', '光谱仪', '虹昌电子', 'SPC-100', 'SN-XPD-002'] as $term) {
            $this->getJsonAs($operator, $ledger.'?search='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.equipment_no', 'XPD-S-002');
        }

        // Both ids are searchable. A numeric term still substring-matches the text
        // columns as well, which is why this asserts presence rather than an exact
        // count: "1" legitimately also finds the model SPC-100.
        $this->assertContains(
            $newer->id,
            collect($this->getJsonAs($operator, $ledger.'?search='.$newer->id)->assertOk()->json('data'))
                ->pluck('inspection_record_id')
                ->all(),
        );
        $this->assertContains(
            $first->id,
            collect($this->getJsonAs($operator, $ledger.'?search='.$first->id)->assertOk()->json('data'))
                ->pluck('equipment_id')
                ->all(),
        );

        // A record whose snapshot text carries no digits isolates the id search, so
        // the id match is the only thing that can produce this row.
        $alpha = $this->equipment(
            'XPD-S-ALPHA',
            name: '色度计',
            serialNo: 'SN-ALPHA',
            manufacturer: '远方',
            model: 'ALPHA',
        );
        $alphaRecord = $this->record($this->sample('S-FILTER-ALPHA'), [$alpha], '2026-08-19 09:00:00');
        $this->assertStringNotContainsString((string) $alphaRecord->id, 'XPD-S-001XPD-S-002SPC-100HAAS-2000SN-XPD-001SN-XPD-002');
        $this->getJsonAs($operator, $ledger.'?search='.$alphaRecord->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inspection_record_id', $alphaRecord->id);

        $this->getJsonAs($operator, $ledger.'?inspection_record_id='.$older->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inspection_record_id', $older->id);
        $this->getJsonAs($operator, $ledger.'?equipment_id='.$second->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.equipment_id', $second->id);

        $this->getJsonAs($operator, $ledger.'?date_from=2026-08-20&date_to=2026-08-20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inspection_record_id', $newer->id);
        $this->getJsonAs($operator, $ledger.'?date_to=2026-08-18')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inspection_record_id', $older->id);
        $this->getJsonAs($operator, $ledger.'?date_from=2026-08-19')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->getJsonAs($operator, $ledger.'?search=XPD&inspection_record_id='.$older->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.equipment_no', 'XPD-S-001');
    }

    public function test_global_equipment_ledger_search_matches_the_association_row_id(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.read']);
        // Digit-free snapshot text, so a numeric term can only match through an id.
        $alpha = $this->equipment('XPD-S-ALPHA', name: '色度计', serialNo: 'SN-ALPHA', manufacturer: '远方', model: 'ALPHA');
        $beta = $this->equipment('XPD-S-BETA', name: '光谱仪', serialNo: 'SN-BETA', manufacturer: '虹昌', model: 'BETA');

        // Burning a couple of child ids pushes the association row id clear of every
        // record id and equipment id, so the assertion below can only pass through
        // the new child-id branch of the search.
        $scratch = $this->record($this->sample('S-SEARCH-SCRATCH'), [$alpha, $beta], '2026-08-17 09:00:00');
        $scratch->delete();

        $target = $this->record($this->sample('S-SEARCH-TARGET'), [$alpha], '2026-08-20 12:27:00');
        $childId = $target->equipment->first()->id;

        $this->assertNotContains($childId, [$target->id, $alpha->id, $beta->id], 'the child id must be isolated from the other ids');
        $this->assertSame(
            0,
            IntegratingSphereInspectionEquipment::query()
                ->where(fn ($query) => $query->where('inspection_record_id', $childId)->orWhere('equipment_id', $childId))
                ->count(),
            'no stored association may match this id through the record or equipment branch',
        );

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/equipment?search='.$childId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $childId)
            ->assertJsonPath('data.0.inspection_record_id', $target->id)
            ->assertJsonPath('data.0.equipment_id', $alpha->id)
            ->assertJsonPath('data.0.equipment_no', 'XPD-S-ALPHA');

        // The dedicated filters keep their own exact-match semantics.
        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/equipment?inspection_record_id='.$childId)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/equipment?equipment_id='.$childId)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_global_equipment_ledger_keeps_snapshots_of_deleted_live_equipment(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.read']);
        $equipment = $this->equipment('XPD-S-001');
        $record = $this->record($this->sample('S-LEDGER-GONE'), [$equipment]);
        $equipmentId = $equipment->id;

        $equipment->delete();

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/equipment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.equipment_id', null)
            ->assertJsonPath('data.0.inspection_record_id', $record->id)
            ->assertJsonPath('data.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.0.equipment_name', '积分球')
            ->assertJsonPath('data.0.manufacturer', '杭州远方')
            ->assertJsonPath('data.0.serial_no', 'SN-XPD-001')
            ->assertJsonPath('data.0.next_calibration_date', '2027-03-01')
            ->assertJsonPath('data.0.operator_name', '点检员');

        // The now-dangling live id must not resurrect the row through the id filter.
        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/equipment?equipment_id='.$equipmentId)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_global_equipment_ledger_paginates(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.read']);
        $first = $this->equipment('XPD-S-001');
        $second = $this->equipment('XPD-S-002', serialNo: 'SN-XPD-002');
        $this->record($this->sample('S-PAGE-1'), [$first, $second], '2026-08-20 12:27:00');
        $this->record($this->sample('S-PAGE-2'), [$first], '2026-08-19 12:27:00');

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/equipment?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1);

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/equipment?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.equipment_no', 'XPD-S-001');
    }

    public function test_show_returns_the_full_equipment_snapshot(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.read']);
        $record = $this->record($this->sample('S-SHOW'), [$this->equipment('XPD-S-001'), $this->equipment('XPD-S-002', serialNo: 'SN-XPD-002')]);

        $this->getJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.equipment')
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.equipment.0.next_calibration_date', '2027-03-01')
            ->assertJsonPath('data.equipment.1.serial_no', 'SN-XPD-002');
    }

    public function test_update_keeps_the_snapshots_it_is_told_to_retain_and_adds_the_new_devices(): void
    {
        $operator = $this->editor();
        $first = $this->equipment('XPD-S-001');
        $second = $this->equipment('XPD-S-002', serialNo: 'SN-XPD-002');
        $third = $this->equipment('XPD-S-003', name: '功率计', serialNo: 'SN-XPD-003');
        $sample = $this->sample('S-UPDATE');
        $otherSample = $this->sample('S-UPDATE-2');
        $record = $this->record($sample, [$first, $second]);
        [, $secondChildId] = $record->equipment->pluck('id')->all();

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            'sample_id' => $otherSample->id,
            'retained_equipment_ids' => [$secondChildId],
            'equipment_ids' => [$third->id],
            'recorded_at' => '2026-08-21 08:00:00',
            ...$this->measurements(),
            'chromaticity_x' => '0.4000',
            'remark' => null,
        ])->assertOk()
            ->assertJsonCount(2, 'data.equipment')
            ->assertJsonPath('data.sample_no', 'S-UPDATE-2')
            ->assertJsonPath('data.chromaticity_x', '0.4000')
            ->assertJsonPath('data.remark', null)
            ->assertJsonPath('data.recorded_at', '2026-08-21 08:00:00')
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-002')
            ->assertJsonPath('data.equipment.0.id', $secondChildId)
            ->assertJsonPath('data.equipment.1.equipment_no', 'XPD-S-003');

        $this->assertDatabaseCount('integrating_sphere_inspection_equipment', 2);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integrating_sphere_inspection_records.update',
            'module' => 'integrating_sphere_inspection_records',
        ]);
    }

    public function test_editing_only_a_measurement_preserves_the_snapshot_of_deleted_ledger_rows(): void
    {
        $operator = $this->editor();
        $first = $this->equipment('XPD-S-001');
        $second = $this->equipment('XPD-S-002', name: '光谱仪', serialNo: 'SN-XPD-002');
        $sample = $this->sample('S-ORPHAN');
        $record = $this->record($sample, [$first, $second]);
        $childIds = $record->equipment->pluck('id')->all();

        $sample->delete();
        $second->delete();
        $this->assertNull($record->fresh()->sample_id);

        // The operator only corrects a measurement; nothing about the devices or the
        // sample is re-declared, so every historical snapshot has to survive intact.
        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            'retained_equipment_ids' => $childIds,
            'equipment_ids' => [],
            ...$this->measurements(),
            'luminous_flux' => '1300.0',
        ])->assertOk()
            ->assertJsonPath('data.sample_id', null)
            ->assertJsonPath('data.sample_no', 'S-ORPHAN')
            ->assertJsonPath('data.luminous_flux', '1300.0')
            ->assertJsonCount(2, 'data.equipment')
            ->assertJsonPath('data.equipment.0.equipment_id', $first->id)
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.equipment.1.equipment_id', null)
            ->assertJsonPath('data.equipment.1.equipment_no', 'XPD-S-002')
            ->assertJsonPath('data.equipment.1.equipment_name', '光谱仪')
            ->assertJsonPath('data.equipment.1.serial_no', 'SN-XPD-002')
            ->assertJsonPath('data.equipment.1.next_calibration_date', '2027-03-01');

        $this->assertDatabaseCount('integrating_sphere_inspection_equipment', 2);
    }

    public function test_editing_a_measurement_keeps_the_sample_snapshot_after_the_ledger_row_is_renamed(): void
    {
        $operator = $this->editor();
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('26010058874-1-1/1');
        $record = $this->record($sample, [$equipment]);

        // The sample survives in the ledger but is renumbered afterwards. The record
        // must keep the number the measurement was actually filed under.
        $sample->update(['sample_no' => '26010058874-1-1/2']);

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            ...$this->measurements(),
            'luminous_flux' => '1300.0',
        ])->assertOk()
            ->assertJsonPath('data.sample_id', $sample->id)
            ->assertJsonPath('data.sample_no', '26010058874-1-1/1')
            ->assertJsonPath('data.luminous_flux', '1300.0');

        $this->assertDatabaseHas('integrating_sphere_inspection_records', [
            'id' => $record->id,
            'sample_id' => $sample->id,
            'sample_no' => '26010058874-1-1/1',
        ]);
    }

    public function test_explicitly_resending_the_sample_id_re_snapshots_the_current_number(): void
    {
        $operator = $this->editor();
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('26010058874-1-1/1');
        $other = $this->sample('26010058874-2-1/1');
        $record = $this->record($sample, [$equipment]);

        $sample->update(['sample_no' => '26010058874-1-1/2']);

        // Re-scanning the same label is a deliberate refresh of the snapshot.
        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            'sample_id' => $sample->id,
            ...$this->measurements(),
        ])->assertOk()
            ->assertJsonPath('data.sample_id', $sample->id)
            ->assertJsonPath('data.sample_no', '26010058874-1-1/2');

        // Scanning a different label switches the record over to that sample.
        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            'sample_id' => $other->id,
            ...$this->measurements(),
        ])->assertOk()
            ->assertJsonPath('data.sample_id', $other->id)
            ->assertJsonPath('data.sample_no', '26010058874-2-1/1');
    }

    public function test_update_retains_every_existing_snapshot_when_the_payload_omits_the_equipment_fields(): void
    {
        $operator = $this->editor();
        $first = $this->equipment('XPD-S-001');
        $second = $this->equipment('XPD-S-002', name: '光谱仪', serialNo: 'SN-XPD-002');
        $sample = $this->sample('S-DEFAULT-RETAIN');
        $record = $this->record($sample, [$first, $second]);

        $sample->delete();
        $second->delete();

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            ...$this->measurements(),
            'voltage' => '221.0',
        ])->assertOk()
            ->assertJsonPath('data.sample_no', 'S-DEFAULT-RETAIN')
            ->assertJsonPath('data.voltage', '221.0')
            ->assertJsonCount(2, 'data.equipment')
            ->assertJsonPath('data.equipment.1.equipment_no', 'XPD-S-002')
            ->assertJsonPath('data.equipment.1.equipment_id', null);
    }

    public function test_update_never_resurrects_a_deleted_ledger_row(): void
    {
        $operator = $this->editor();
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-NO-RESURRECT');
        $record = $this->record($sample, [$equipment]);
        $sampleId = $sample->id;
        $equipmentId = $equipment->id;

        $sample->delete();
        $equipment->delete();

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            ...$this->measurements(),
        ])->assertOk();

        $this->assertDatabaseMissing('samples', ['id' => $sampleId]);
        $this->assertDatabaseMissing('equipment', ['id' => $equipmentId]);
        $this->assertDatabaseHas('integrating_sphere_inspection_equipment', [
            'inspection_record_id' => $record->id,
            'equipment_id' => null,
            'equipment_no' => 'XPD-S-001',
        ]);
    }

    public function test_update_refuses_a_retained_snapshot_that_belongs_to_another_record(): void
    {
        $operator = $this->editor();
        $mine = $this->record($this->sample('S-MINE'), [$this->equipment('XPD-S-001')]);
        $theirs = $this->record($this->sample('S-THEIRS'), [$this->equipment('XPD-S-002', serialNo: 'SN-XPD-002')]);
        $foreignChildId = $theirs->equipment->first()->id;

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$mine->id}", [
            'retained_equipment_ids' => [$foreignChildId],
            'equipment_ids' => [],
            ...$this->measurements(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('retained_equipment_ids');

        $this->assertSame(1, $theirs->fresh()->equipment()->count());
        $this->assertSame('XPD-S-001', $mine->fresh(['equipment'])->equipment->first()->equipment_no);
    }

    public function test_update_refuses_to_snapshot_a_device_that_is_already_retained(): void
    {
        $operator = $this->editor();
        $equipment = $this->equipment('XPD-S-001');
        $record = $this->record($this->sample('S-DUP'), [$equipment]);
        $childId = $record->equipment->first()->id;

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            'retained_equipment_ids' => [$childId],
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('equipment_ids');

        $this->assertSame(1, $record->fresh()->equipment()->count());
    }

    public function test_update_refuses_to_leave_a_record_without_any_device(): void
    {
        $operator = $this->editor();
        $record = $this->record($this->sample('S-EMPTY'), [$this->equipment('XPD-S-001')]);

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            'retained_equipment_ids' => [],
            'equipment_ids' => [],
            ...$this->measurements(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('equipment_ids');

        $this->assertSame(1, $record->fresh()->equipment()->count());
    }

    public function test_update_can_drop_a_snapshot_the_operator_explicitly_leaves_out(): void
    {
        $operator = $this->editor();
        $first = $this->equipment('XPD-S-001');
        $second = $this->equipment('XPD-S-002', serialNo: 'SN-XPD-002');
        $record = $this->record($this->sample('S-DROP'), [$first, $second]);
        [$firstChildId] = $record->equipment->pluck('id')->all();

        $second->delete();

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            'retained_equipment_ids' => [$firstChildId],
            'equipment_ids' => [],
            ...$this->measurements(),
        ])->assertOk()
            ->assertJsonCount(1, 'data.equipment')
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001');

        $this->assertDatabaseCount('integrating_sphere_inspection_equipment', 1);
    }

    public function test_delete_removes_the_record_with_its_children_and_is_audited(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.delete']);
        $record = $this->record($this->sample('S-DELETE'), [$this->equipment('XPD-S-001')]);

        $this->deleteJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseCount('integrating_sphere_inspection_records', 0);
        $this->assertDatabaseCount('integrating_sphere_inspection_equipment', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integrating_sphere_inspection_records.delete',
            'module' => 'integrating_sphere_inspection_records',
        ]);
    }

    public function test_snapshots_survive_ledger_edits_and_deletions(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.read']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-HISTORY');
        $record = $this->record($sample, [$equipment]);

        $equipment->update([
            'name' => '积分球（已更名）',
            'manufacturer' => '新厂家',
            'model' => 'NEW-MODEL',
            'serial_no' => 'SN-CHANGED',
            'next_calibration_date' => '2030-01-01',
        ]);
        $sample->update(['sample_no' => 'S-HISTORY-RENAMED']);

        $this->getJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.sample_no', 'S-HISTORY')
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.equipment.0.equipment_name', '积分球')
            ->assertJsonPath('data.equipment.0.manufacturer', '杭州远方')
            ->assertJsonPath('data.equipment.0.model', 'HAAS-2000')
            ->assertJsonPath('data.equipment.0.serial_no', 'SN-XPD-001')
            ->assertJsonPath('data.equipment.0.next_calibration_date', '2027-03-01');

        $equipment->delete();
        $sample->delete();

        $this->getJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.sample_id', null)
            ->assertJsonPath('data.sample_no', 'S-HISTORY')
            ->assertJsonPath('data.equipment.0.equipment_id', null)
            ->assertJsonPath('data.equipment.0.equipment_no', 'XPD-S-001')
            ->assertJsonPath('data.equipment.0.equipment_name', '积分球');
    }

    public function test_lookup_resolves_active_system_codes_without_equipment_system_read_permission(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create']);
        $system = $this->system('sys-01');

        $this->getJsonAs($operator, '/api/equipment-systems')->assertForbidden();

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/lookup?type=system&code=sys-01')
            ->assertOk()
            ->assertJsonPath('data.id', $system->id)
            ->assertJsonPath('data.code', 'sys-01')
            ->assertJsonPath('data.name', '系统1')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_system_lookup_is_available_to_editors_without_create_permission(): void
    {
        $editor = $this->userWithPermissions(['integrating_sphere_inspection_records.update']);
        $this->system('sys-edit');

        $this->getJsonAs($editor, '/api/integrating-sphere-inspection-records/lookup?type=system&code=sys-edit')
            ->assertOk()
            ->assertJsonPath('data.code', 'sys-edit');
    }

    public function test_lookup_refuses_disabled_and_unknown_system_codes(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create']);
        $this->system('sys-off', status: 'disabled');

        // A disabled system is still valid history, but it can never be the answer to a
        // fresh scan, so the lookup that feeds new selections refuses it outright.
        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/lookup?type=system&code=sys-off')
            ->assertNotFound();
        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/lookup?type=system&code=sys-nope')
            ->assertNotFound();
        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records/lookup?type=nonsense&code=sys-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_creating_a_record_requires_a_live_active_system_and_snapshots_its_code(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create', 'integrating_sphere_inspection_records.read']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-SYSTEM');
        $system = $this->system('sys-01');

        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [
            'sample_id' => $sample->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ])->assertStatus(422)->assertJsonValidationErrors('equipment_system_id');

        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ])->assertCreated()
            ->assertJsonPath('data.equipment_system_id', $system->id)
            ->assertJsonPath('data.system_code', 'sys-01');

        $this->assertDatabaseHas('integrating_sphere_inspection_records', [
            'equipment_system_id' => $system->id,
            'system_code' => 'sys-01',
        ]);
    }

    public function test_creation_rejects_an_unknown_or_disabled_system(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.create']);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-SYSTEM-INVALID');
        $disabled = $this->system('sys-off', status: 'disabled');
        $base = [
            'sample_id' => $sample->id,
            'equipment_system_id' => $this->system()->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ];

        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'equipment_system_id' => $disabled->id + 999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('equipment_system_id');
        $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [...$base, 'equipment_system_id' => $disabled->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('equipment_system_id');

        $this->assertDatabaseCount('integrating_sphere_inspection_records', 0);
    }

    public function test_a_default_edit_preserves_the_system_code_after_a_rename(): void
    {
        $operator = $this->editor();
        $system = $this->system('sys-01');
        $record = $this->record($this->sample('S-SYS-RENAME'), [$this->equipment('XPD-S-001')], system: $system);

        $system->update(['code' => 'sys-01-renamed']);

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            ...$this->measurements(),
            'luminous_flux' => '1300.0',
        ])->assertOk()
            ->assertJsonPath('data.equipment_system_id', $system->id)
            ->assertJsonPath('data.system_code', 'sys-01')
            ->assertJsonPath('data.luminous_flux', '1300.0');
    }

    public function test_a_default_edit_preserves_the_system_code_after_the_system_is_disabled(): void
    {
        $operator = $this->editor();
        $system = $this->system('sys-01');
        $record = $this->record($this->sample('S-SYS-DISABLE'), [$this->equipment('XPD-S-001')], system: $system);

        $system->update(['status' => 'disabled']);

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            ...$this->measurements(),
            'voltage' => '221.0',
        ])->assertOk()
            ->assertJsonPath('data.equipment_system_id', $system->id)
            ->assertJsonPath('data.system_code', 'sys-01')
            ->assertJsonPath('data.voltage', '221.0');
    }

    public function test_a_default_edit_preserves_the_system_code_after_the_system_is_deleted(): void
    {
        $operator = $this->editor();
        $system = $this->system('sys-01');
        $record = $this->record($this->sample('S-SYS-DELETE'), [$this->equipment('XPD-S-001')], system: $system);

        $system->delete();

        // The foreign key is cleared by the database, but the snapshot is the only
        // evidence left of which system the measurement was taken on, so it stays.
        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            ...$this->measurements(),
            'voltage' => '221.0',
        ])->assertOk()
            ->assertJsonPath('data.equipment_system_id', null)
            ->assertJsonPath('data.system_code', 'sys-01');

        $this->assertDatabaseHas('integrating_sphere_inspection_records', [
            'id' => $record->id,
            'equipment_system_id' => null,
            'system_code' => 'sys-01',
        ]);
    }

    public function test_explicitly_selecting_a_system_snapshots_its_current_code(): void
    {
        $operator = $this->editor();
        $system = $this->system('sys-01');
        $other = $this->system('sys-02', name: '系统2');
        $record = $this->record($this->sample('S-SYS-REPLACE'), [$this->equipment('XPD-S-001')], system: $system);

        $system->update(['code' => 'sys-01-renamed']);

        // Re-scanning the same label is a deliberate refresh of the snapshot.
        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            'equipment_system_id' => $system->id,
            ...$this->measurements(),
        ])->assertOk()
            ->assertJsonPath('data.equipment_system_id', $system->id)
            ->assertJsonPath('data.system_code', 'sys-01-renamed');

        // Scanning a different label switches the record over to that system.
        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            'equipment_system_id' => $other->id,
            ...$this->measurements(),
        ])->assertOk()
            ->assertJsonPath('data.equipment_system_id', $other->id)
            ->assertJsonPath('data.system_code', 'sys-02');
    }

    public function test_an_explicit_replacement_must_be_an_active_system(): void
    {
        $operator = $this->editor();
        $system = $this->system('sys-01');
        $disabled = $this->system('sys-off', status: 'disabled');
        $record = $this->record($this->sample('S-SYS-INACTIVE'), [$this->equipment('XPD-S-001')], system: $system);

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            'equipment_system_id' => $disabled->id,
            ...$this->measurements(),
        ])->assertStatus(422)->assertJsonValidationErrors('equipment_system_id');

        $this->assertDatabaseHas('integrating_sphere_inspection_records', [
            'id' => $record->id,
            'system_code' => 'sys-01',
        ]);
    }

    public function test_index_search_matches_the_system_code(): void
    {
        $operator = $this->userWithPermissions(['integrating_sphere_inspection_records.read']);
        $matching = $this->record($this->sample('S-SYS-A'), [$this->equipment('XPD-S-A')], system: $this->system('sys-01'));
        $this->record($this->sample('S-SYS-B'), [$this->equipment('XPD-S-B')], system: $this->system('sys-02', name: '系统2'));

        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records?search=sys-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.system_code', 'sys-01');

        // The device search the list has always offered keeps working alongside it.
        $this->getJsonAs($operator, '/api/integrating-sphere-inspection-records?search=XPD-S-B')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sample_no', 'S-SYS-B');
    }

    public function test_legacy_records_without_a_system_stay_readable_and_editable(): void
    {
        $operator = $this->editor();
        $record = $this->record($this->sample('S-LEGACY'), [$this->equipment('XPD-S-LEGACY')]);

        $this->getJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.equipment_system_id', null)
            ->assertJsonPath('data.system_code', null);

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$record->id}", [
            ...$this->measurements(),
            'voltage' => '221.0',
        ])->assertOk()
            ->assertJsonPath('data.equipment_system_id', null)
            ->assertJsonPath('data.system_code', null)
            ->assertJsonPath('data.voltage', '221.0');
    }

    public function test_audit_payloads_carry_the_system_snapshot_fields(): void
    {
        $operator = $this->userWithPermissions([
            'integrating_sphere_inspection_records.create',
            'integrating_sphere_inspection_records.read',
            'integrating_sphere_inspection_records.update',
            'integrating_sphere_inspection_records.delete',
        ]);
        $equipment = $this->equipment('XPD-S-001');
        $sample = $this->sample('S-SYS-AUDIT');
        $system = $this->system('sys-01');
        $other = $this->system('sys-02', name: '系统2');

        $recordId = $this->postJsonAs($operator, '/api/integrating-sphere-inspection-records', [
            'sample_id' => $sample->id,
            'equipment_system_id' => $system->id,
            'equipment_ids' => [$equipment->id],
            ...$this->measurements(),
        ])->assertCreated()->json('data.id');

        $created = AuditLog::query()->where('action', 'integrating_sphere_inspection_records.create')->latest('id')->firstOrFail();
        $this->assertSame($system->id, data_get($created->after_values, 'equipment_system_id'));
        $this->assertSame('sys-01', data_get($created->after_values, 'system_code'));

        $this->putJsonAs($operator, "/api/integrating-sphere-inspection-records/{$recordId}", [
            'equipment_system_id' => $other->id,
            ...$this->measurements(),
        ])->assertOk();

        $updated = AuditLog::query()->where('action', 'integrating_sphere_inspection_records.update')->latest('id')->firstOrFail();
        $this->assertSame('sys-01', data_get($updated->before_values, 'system_code'));
        $this->assertSame('sys-02', data_get($updated->after_values, 'system_code'));
        $this->assertSame($other->id, data_get($updated->after_values, 'equipment_system_id'));

        $this->deleteJsonAs($operator, "/api/integrating-sphere-inspection-records/{$recordId}")->assertOk();

        $deleted = AuditLog::query()->where('action', 'integrating_sphere_inspection_records.delete')->latest('id')->firstOrFail();
        $this->assertSame('sys-02', data_get($deleted->before_values, 'system_code'));
    }

    /**
     * @return array<string, mixed>
     */
    private function measurements(): array
    {
        return [
            'chromaticity_x' => '0.3633',
            'chromaticity_y' => '0.3549',
            'dominant_wavelength' => '580.5',
            'peak_wavelength' => '601.2',
            'color_temperature' => 4360,
            'color_rendering_index' => '88.4',
            'luminous_flux' => '1234.5',
            'voltage' => '220.0',
            'current' => '0.0451',
            'power' => '9.8765',
            'power_factor' => '0.9876',
            'frequency' => 50,
            'remark' => '首件点检',
        ];
    }

    /**
     * Passing no system builds a legacy row: the historical columns stay null exactly
     * as they are for every record written before the system code existed.
     *
     * @param  list<Equipment>  $devices
     */
    private function record(
        Sample $sample,
        array $devices,
        string $recordedAt = '2026-08-20 12:27:00',
        ?EquipmentSystem $system = null,
    ): IntegratingSphereInspectionRecord {
        $record = IntegratingSphereInspectionRecord::query()->create([
            'sample_id' => $sample->id,
            'sample_no' => $sample->sample_no,
            'equipment_system_id' => $system?->id,
            'system_code' => $system?->code,
            'recorded_at' => $recordedAt,
            'operator_name' => '点检员',
            ...collect($this->measurements())->except('remark')->all(),
        ]);

        foreach ($devices as $device) {
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

        return $record->fresh(['equipment']);
    }

    private function system(string $code = 'SYS-01', string $name = '系统1', string $status = 'active'): EquipmentSystem
    {
        return EquipmentSystem::query()->create(['code' => $code, 'name' => $name, 'status' => $status]);
    }

    private function equipment(
        string $equipmentNo,
        string $name = '积分球',
        string $serialNo = 'SN-XPD-001',
        string $manufacturer = '杭州远方',
        string $model = 'HAAS-2000',
    ): Equipment {
        return Equipment::query()->create([
            'equipment_no' => $equipmentNo,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'model' => $model,
            'serial_no' => $serialNo,
            'next_calibration_date' => '2027-03-01',
            'status' => 'active',
        ]);
    }

    private function sample(string $sampleNo): Sample
    {
        $order = TestOrder::query()->first()
            ?? TestOrder::query()->create([
                'order_no' => 'ORDER-SPHERE',
                'contract_no' => 'CONTRACT-SPHERE',
                'order_date' => '2026-08-20',
                'urgency' => 'normal',
                'client_company' => '中山市样品客户',
                'sample_status' => 'received',
            ]);

        return Sample::query()->create([
            'test_order_id' => $order->id,
            'sample_no' => $sampleNo,
            'sample_name' => '灯具',
            'model' => 'LD-1',
            'quantity' => 1,
            'status' => 'pending',
            'current_holder' => '样品室',
        ]);
    }

    private function editor(): User
    {
        return $this->userWithPermissions([
            'integrating_sphere_inspection_records.create',
            'integrating_sphere_inspection_records.read',
            'integrating_sphere_inspection_records.update',
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_integrating_sphere_'.str()->random(8), 'guard_name' => 'web']);
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
}

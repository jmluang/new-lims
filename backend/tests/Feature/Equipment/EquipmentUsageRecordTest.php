<?php

namespace Tests\Feature\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentUsageRecord;
use App\Models\Sample;
use App\Models\TestOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EquipmentUsageRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_starting_usage_creates_cross_product_records_and_can_end_them(): void
    {
        $operator = $this->userWithPermissions(['equipment_usage_records.read', 'equipment_usage_records.create', 'equipment_usage_records.update']);
        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-USAGE-001',
            'name' => '恒温箱',
            'model' => 'HT-1',
            'status' => 'active',
        ]);
        $secondEquipment = Equipment::query()->create([
            'equipment_no' => 'EQ-USAGE-002',
            'name' => '光照箱',
            'model' => 'LT-1',
            'status' => 'active',
        ]);
        $sampleA = $this->sample('SAMPLE-A');
        $sampleB = $this->sample('SAMPLE-B');

        $startResponse = $this->postJsonAs($operator, '/api/equipment-usage-records/start', [
            'equipment_ids' => [$equipment->id, $secondEquipment->id],
            'sample_ids' => [$sampleA->id, $sampleB->id],
            'start_time' => '2026-06-12 09:30:00',
            'remark' => '性能测试',
        ])->assertCreated()
            ->assertJsonPath('meta.created_count', 4)
            ->assertJsonPath('data.0.equipment_no', 'EQ-USAGE-001')
            ->assertJsonPath('data.0.sample_no', 'SAMPLE-A')
            ->assertJsonPath('data.0.status', 'using');

        $usageBatchId = $startResponse->json('data.0.usage_batch_id');
        $this->assertNotEmpty($usageBatchId);
        $this->assertSame(
            [$usageBatchId],
            collect($startResponse->json('data'))->pluck('usage_batch_id')->unique()->values()->all(),
        );

        $this->assertDatabaseHas('equipment_usage_records', [
            'equipment_id' => $equipment->id,
            'sample_id' => $sampleA->id,
            'equipment_no' => 'EQ-USAGE-001',
            'sample_no' => 'SAMPLE-A',
            'remark' => '性能测试',
        ]);

        $this->getJsonAs($operator, '/api/equipment-usage-records?status=using')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'using');

        $recordId = EquipmentUsageRecord::query()
            ->where('equipment_id', $equipment->id)
            ->where('sample_id', $sampleA->id)
            ->valueOrFail('id');

        $this->putJsonAs($operator, "/api/equipment-usage-records/{$recordId}", [
            'start_time' => '2026-06-12 09:45:00',
            'remark' => '性能测试-复核',
        ])->assertOk()
            ->assertJsonPath('data.start_time', '2026-06-12 09:45:00')
            ->assertJsonPath('data.remark', '性能测试-复核');

        $this->postJsonAs($operator, "/api/equipment-usage-records/{$recordId}/end", [
            'end_time' => '2026-06-12 11:00:00',
        ])->assertOk()
            ->assertJsonPath('data.status', 'finished')
            ->assertJsonPath('data.end_time', '2026-06-12 11:00:00')
            ->assertJsonPath('meta.updated_count', 2);

        $this->assertSame(
            2,
            EquipmentUsageRecord::query()
                ->where('usage_batch_id', $usageBatchId)
                ->where('sample_id', $sampleA->id)
                ->whereNotNull('end_time')
                ->count(),
        );
        $this->assertSame(
            2,
            EquipmentUsageRecord::query()
                ->where('usage_batch_id', $usageBatchId)
                ->where('sample_id', $sampleB->id)
                ->whereNull('end_time')
                ->count(),
        );
    }

    public function test_form_options_do_not_require_equipment_or_sample_read_permissions(): void
    {
        $operator = $this->userWithPermissions(['equipment_usage_records.create']);
        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-OPTION',
            'name' => '可选设备',
            'status' => 'active',
        ]);
        $sample = $this->sample('SAMPLE-OPTION');

        $this->getJsonAs($operator, '/api/equipment')->assertForbidden();
        $this->getJsonAs($operator, '/api/samples')->assertForbidden();

        $this->getJsonAs($operator, '/api/equipment-usage-records/form-options')
            ->assertOk()
            ->assertJsonPath('data.equipment.0.id', $equipment->id)
            ->assertJsonPath('data.equipment.0.equipment_no', 'EQ-OPTION')
            ->assertJsonPath('data.samples.0.id', $sample->id)
            ->assertJsonPath('data.samples.0.sample_no', 'SAMPLE-OPTION');
    }

    public function test_usage_lookup_resolves_equipment_and_samples_by_number(): void
    {
        $operator = $this->userWithPermissions(['equipment_usage_records.create']);
        $equipment = Equipment::query()->create(['equipment_no' => 'EQ-SCAN-001', 'name' => '积分球', 'status' => 'active']);
        $sample = $this->sample('S-SCAN-001');

        $this->getJsonAs($operator, '/api/equipment-usage-records/lookup?type=equipment&code=EQ-SCAN-001')
            ->assertOk()
            ->assertJsonPath('data.id', $equipment->id)
            ->assertJsonPath('data.equipment_no', 'EQ-SCAN-001');

        $this->getJsonAs($operator, '/api/equipment-usage-records/lookup?type=sample&code=S-SCAN-001')
            ->assertOk()
            ->assertJsonPath('data.id', $sample->id)
            ->assertJsonPath('data.sample_no', 'S-SCAN-001');
    }

    public function test_usage_lookup_returns_404_for_unknown_code(): void
    {
        $operator = $this->userWithPermissions(['equipment_usage_records.create']);

        $this->getJsonAs($operator, '/api/equipment-usage-records/lookup?type=equipment&code=NOPE')
            ->assertNotFound();
    }

    public function test_usage_records_sort_by_start_time_desc_then_sample_no_asc(): void
    {
        $operator = $this->userWithPermissions(['equipment_usage_records.read']);
        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-SORT',
            'name' => '排序设备',
            'status' => 'active',
        ]);
        $sampleA = $this->sample('SAMPLE-A');
        $sampleB = $this->sample('SAMPLE-B');
        $sampleC = $this->sample('SAMPLE-C');

        $this->usageRecord($equipment, $sampleC, '2026-06-11 09:30:00');
        $this->usageRecord($equipment, $sampleA, '2026-06-12 09:30:00');
        $this->usageRecord($equipment, $sampleB, '2026-06-12 09:30:00');

        $this->getJsonAs($operator, '/api/equipment-usage-records?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.sample_no', 'SAMPLE-A')
            ->assertJsonPath('data.1.sample_no', 'SAMPLE-B')
            ->assertJsonPath('data.2.sample_no', 'SAMPLE-C');
    }

    private function sample(string $sampleNo): Sample
    {
        $order = TestOrder::query()->first()
            ?? TestOrder::query()->create([
                'order_no' => 'ORDER-USAGE',
                'contract_no' => 'CONTRACT-USAGE',
                'order_date' => '2026-06-12',
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

    private function usageRecord(Equipment $equipment, Sample $sample, string $startTime): EquipmentUsageRecord
    {
        return EquipmentUsageRecord::query()->create([
            'equipment_id' => $equipment->id,
            'sample_id' => $sample->id,
            'equipment_no' => $equipment->equipment_no,
            'equipment_name' => $equipment->name,
            'sample_no' => $sample->sample_no,
            'sample_name' => $sample->sample_name,
            'sample_model' => $sample->model,
            'start_time' => $startTime,
        ]);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_equipment_usage_'.str()->random(8), 'guard_name' => 'web']);
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
}

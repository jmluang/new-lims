<?php

namespace Tests\Feature\Samples;

use App\Models\Sample;
use App\Models\TestOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SampleFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_sample_flow_actions_update_sample_and_append_flow_rows(): void
    {
        Carbon::setTestNow('2026-06-15 12:17:08');
        $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.read', 'sample_flows.create', 'sample_flows.return_room']);
        $operator->update(['name' => '流转操作员']);
        $sample = $this->sample();

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'lend',
            'holder_to' => '检测员A',
            'remark' => '领取测试',
        ])->assertCreated()
            ->assertJsonPath('data.action_type', 'lend')
            ->assertJsonPath('data.holder_to', '流转操作员')
            ->assertJsonPath('data.action_time', '2026-06-15 12:17:08');

        $this->assertDatabaseHas('samples', ['id' => $sample->id, 'status' => 'testing', 'current_holder' => '流转操作员']);
        $this->assertDatabaseHas('sample_flows', ['sample_id' => $sample->id, 'action_type' => 'lend', 'holder_from' => '样品室', 'holder_to' => '流转操作员']);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'send_out',
            'holder_to' => '分包实验室',
            'location_to' => '分包实验室地址',
            'remark' => '分包测试',
        ])->assertCreated();

        $this->assertDatabaseHas('samples', ['id' => $sample->id, 'status' => 'outsourced', 'current_holder' => '分包实验室']);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'receive_back',
            'location_to' => '样品室 A1',
            'remark' => '外发退回',
        ])->assertCreated();

        $this->assertDatabaseHas('samples', ['id' => $sample->id, 'status' => 'outsource_returned', 'current_holder' => '样品室', 'current_location' => '样品室 A1']);

        $this->getJsonAs($operator, "/api/samples/{$sample->id}/flows")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.action_time', '2026-06-15 12:17:08');
    }

    public function test_lend_transfer_and_return_room_update_sample_and_append_flows(): void
    {
        $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.read', 'sample_flows.create', 'sample_flows.return_room']);
        $operator->update(['name' => '流转操作员']);
        $sample = $this->receivedSample([
            'status' => 'pending',
            'current_holder' => '样品室',
            'current_location' => '样品室',
        ]);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'lend',
            'holder_to' => 'Alice',
            'location_to' => '实验区A',
            'remark' => 'Start test',
        ])->assertCreated();

        $this->assertDatabaseHas('samples', [
            'id' => $sample->id,
            'status' => 'testing',
            'current_holder' => '流转操作员',
            'current_location' => '实验区A',
        ]);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'transfer',
            'holder_to' => 'Bob',
            'location_to' => '实验区B',
        ])->assertCreated();

        $this->assertDatabaseHas('sample_flows', [
            'sample_id' => $sample->id,
            'action_type' => 'transfer',
            'holder_from' => '流转操作员',
            'holder_to' => '流转操作员',
        ]);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'return_room',
            'location_to' => '样品室',
        ])->assertCreated();

        $this->assertDatabaseCount('sample_flows', 3);
        $this->assertDatabaseHas('samples', [
            'id' => $sample->id,
            'status' => 'pending',
            'current_holder' => '样品室',
            'current_location' => '样品室',
        ]);
    }

    public function test_return_client_marks_sample_returned_to_customer_and_appends_flow(): void
    {
        Carbon::setTestNow('2026-06-19 10:20:30');
        $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.read', 'sample_flows.create']);
        $sample = $this->receivedSample([
            'status' => 'completed',
            'current_holder' => '样品室',
            'current_location' => '样品室 A1',
        ]);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'return_client',
            'remark' => '客户已签收',
        ])->assertCreated()
            ->assertJsonPath('data.action_type', 'return_client')
            ->assertJsonPath('data.holder_from', '样品室')
            ->assertJsonPath('data.holder_to', '客户')
            ->assertJsonPath('data.location_from', '样品室 A1')
            ->assertJsonPath('data.location_to', '样品室 A1')
            ->assertJsonPath('data.remark', '客户已签收')
            ->assertJsonPath('data.action_time', '2026-06-19 10:20:30');

        $this->assertDatabaseHas('samples', [
            'id' => $sample->id,
            'status' => 'returned',
            'current_holder' => '客户',
            'current_location' => '样品室 A1',
        ]);

        $this->assertDatabaseHas('sample_flows', [
            'sample_id' => $sample->id,
            'action_type' => 'return_client',
            'holder_from' => '样品室',
            'holder_to' => '客户',
            'location_from' => '样品室 A1',
            'location_to' => '样品室 A1',
            'remark' => '客户已签收',
        ]);
    }

    public function test_sample_flow_rejects_return_room_without_return_room_permission(): void
    {
        $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.create']);
        $sample = $this->receivedSample([
            'status' => 'testing',
            'current_holder' => 'Alice',
            'current_location' => '实验区A',
        ]);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'return_room',
            'location_to' => '样品室',
        ])->assertForbidden()
            ->assertJsonPath('permission', 'sample_flows.return_room');

        $this->assertDatabaseCount('sample_flows', 0);
    }

    public function test_sample_flow_rejects_actions_not_available_for_current_sample_state(): void
    {
        $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.create', 'sample_flows.return_room']);

        $returned = $this->receivedSample([
            'status' => 'returned',
            'current_holder' => '客户',
        ]);

        $this->postJsonAs($operator, "/api/samples/{$returned->id}/flows", [
            'action_type' => 'return_client',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('action_type');

        $returned->update([
            'status' => 'pending',
            'current_holder' => '样品室',
        ]);

        $this->postJsonAs($operator, "/api/samples/{$returned->id}/flows", [
            'action_type' => 'return_room',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('action_type');

        $this->postJsonAs($operator, "/api/samples/{$returned->id}/flows", [
            'action_type' => 'receive_back',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('action_type');

        $this->assertDatabaseCount('sample_flows', 0);
    }

    public function test_global_sample_flow_index_filters_and_returns_sample_snapshots(): void
    {
        $operator = $this->userWithPermissions(['sample_flows.read']);
        $order = TestOrder::query()->create([
            'order_no' => 'FLOW',
            'contract_no' => 'FLOW-GLOBAL',
            'order_date' => '2026-05-29',
            'urgency' => 'normal',
            'client_company' => '中山市XXX有限公司',
            'sample_status' => 'received',
        ]);
        $lamp = Sample::query()->create([
            'test_order_id' => $order->id,
            'delivery_sequence' => 1,
            'sample_no' => 'SAMPLE-LAMP-001',
            'sample_name' => '路灯',
            'specification' => 'LD',
            'model' => 'LD-100',
            'quantity' => 1,
            'status' => 'pending',
            'current_holder' => '样品室',
            'current_location' => '样品室',
            'received_date' => '2026-05-29',
            'sort_order' => 1,
            'delivery_received_count' => 1,
        ]);
        $cable = Sample::query()->create([
            'test_order_id' => $order->id,
            'delivery_sequence' => 2,
            'sample_no' => 'SAMPLE-CABLE-002',
            'sample_name' => '电缆',
            'specification' => 'CB',
            'model' => 'CB-200',
            'quantity' => 1,
            'status' => 'pending',
            'current_holder' => '样品室',
            'current_location' => '样品室',
            'received_date' => '2026-05-29',
            'sort_order' => 2,
            'delivery_received_count' => 1,
        ]);

        $lamp->flows()->create([
            'action_type' => 'lend',
            'action_time' => '2026-06-14 10:00:00',
            'holder_from' => '样品室',
            'holder_to' => 'Alice',
            'location_from' => '样品室',
            'location_to' => '实验区A',
        ]);
        $lamp->flows()->create([
            'action_type' => 'return_room',
            'action_time' => '2026-06-16 09:30:00',
            'holder_from' => 'Alice',
            'holder_to' => '样品室',
            'location_from' => '实验区A',
            'location_to' => '样品室',
        ]);
        $cable->flows()->create([
            'action_type' => 'transfer',
            'action_time' => '2026-06-15 08:00:00',
            'holder_from' => 'Bob',
            'holder_to' => 'Carol',
            'location_from' => '实验区B',
            'location_to' => '实验区C',
        ]);

        $this->getJsonAs($operator, '/api/sample-flows?search=lamp&action_type=return_room&action_time_from=2026-06-15&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action_type', 'return_room')
            ->assertJsonPath('data.0.sample.sample_no', 'SAMPLE-LAMP-001')
            ->assertJsonPath('data.0.sample.sample_name', '路灯')
            ->assertJsonPath('data.0.sample.order_no', 'FLOW')
            ->assertJsonPath('meta.total', 1);

        $this->getJsonAs($operator, '/api/sample-flows?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.action_time', '2026-06-16 09:30:00')
            ->assertJsonPath('data.1.action_time', '2026-06-15 08:00:00')
            ->assertJsonPath('data.2.action_time', '2026-06-14 10:00:00')
            ->assertJsonPath('meta.total', 3);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function receivedSample(array $overrides = []): Sample
    {
        $order = TestOrder::query()->create([
            'order_no' => 'FLOW',
            'contract_no' => 'FLOW',
            'order_date' => '2026-05-29',
            'urgency' => 'normal',
            'client_company' => '中山市XXX有限公司',
            'sample_status' => 'received',
        ]);

        return Sample::query()->create([
            'test_order_id' => $order->id,
            'delivery_sequence' => 1,
            'sample_no' => 'FLOW-1-1/1',
            'sample_name' => '路灯',
            'specification' => 'LD',
            'model' => 'LD-100',
            'quantity' => 1,
            'status' => 'pending',
            'current_holder' => '样品室',
            'current_location' => '样品室',
            'received_date' => '2026-05-29',
            'sort_order' => 1,
            'delivery_received_count' => 1,
            ...$overrides,
        ]);
    }

    private function sample(): Sample
    {
        $order = TestOrder::query()->create([
            'order_no' => 'FLOW',
            'contract_no' => 'FLOW',
            'order_date' => '2026-05-29',
            'urgency' => 'normal',
            'client_company' => '中山市XXX有限公司',
            'sample_status' => 'received',
        ]);

        return Sample::query()->create([
            'test_order_id' => $order->id,
            'delivery_sequence' => 1,
            'sample_no' => 'FLOW-1-1/1',
            'sample_name' => '路灯',
            'specification' => 'LD',
            'model' => 'LD-100',
            'quantity' => 1,
            'status' => 'pending',
            'current_holder' => '样品室',
            'current_location' => '样品室 A1',
            'received_date' => '2026-05-29',
            'sort_order' => 1,
            'delivery_received_count' => 1,
        ]);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'sample_flow_'.str()->random(8), 'guard_name' => 'web']);
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

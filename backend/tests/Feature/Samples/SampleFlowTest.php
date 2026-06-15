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
        $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.read', 'sample_flows.create']);
        $sample = $this->sample();

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'lend',
            'holder_to' => '检测员A',
            'remark' => '领取测试',
        ])->assertCreated()
            ->assertJsonPath('data.action_type', 'lend')
            ->assertJsonPath('data.action_time', '2026-06-15 12:17:08');

        $this->assertDatabaseHas('samples', ['id' => $sample->id, 'status' => 'testing', 'current_holder' => '检测员A']);
        $this->assertDatabaseHas('sample_flows', ['sample_id' => $sample->id, 'action_type' => 'lend', 'holder_from' => '样品室', 'holder_to' => '检测员A']);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'send_out',
            'holder_to' => '分包实验室',
            'location_to' => '分包实验室地址',
            'remark' => '分包测试',
        ])->assertCreated();

        $this->assertDatabaseHas('samples', ['id' => $sample->id, 'status' => 'outsourced', 'current_holder' => '分包实验室']);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'return_room',
            'location_to' => '样品室 A1',
            'remark' => '测试完成归还',
        ])->assertCreated();

        $this->assertDatabaseHas('samples', ['id' => $sample->id, 'status' => 'pending', 'current_holder' => '样品室', 'current_location' => '样品室 A1']);

        $this->getJsonAs($operator, "/api/samples/{$sample->id}/flows")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.action_time', '2026-06-15 12:17:08');
    }

    public function test_lend_transfer_and_return_room_update_sample_and_append_flows(): void
    {
        $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.read', 'sample_flows.create']);
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
            'current_holder' => 'Alice',
            'current_location' => '实验区A',
        ]);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/flows", [
            'action_type' => 'transfer',
            'holder_to' => 'Bob',
            'location_to' => '实验区B',
        ])->assertCreated();

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

<?php

namespace Tests\Feature\Samples;

use App\Models\EquipmentLocation;
use App\Models\TestOrder;
use App\Models\TestOrderSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SampleReceiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_receive_samples_numbers_each_delivery_and_records_flows(): void
    {
        $receiver = $this->userWithPermissions(['samples.receive', 'samples.read']);
        [$order, $orderSamples] = $this->orderWithSamples('ORDER');

        $this->postJsonAs($receiver, '/api/samples/receive', [
            'test_order_id' => $order->id,
            'received_date' => '2026-05-29',
            'storage_condition' => '常温',
            'current_location' => '样品室 A1',
            'batch_no' => 'B001',
            'samples' => [
                $this->receiveRow($orderSamples[0]),
                $this->receiveRow($orderSamples[1], sampleName: '控制器-1'),
                $this->receiveRow($orderSamples[1], sampleName: '控制器-2'),
            ],
        ])->assertCreated()
            ->assertJsonPath('meta.delivery_received_count', 3)
            ->assertJsonPath('meta.rejected_count', 0)
            ->assertJsonPath('data.0.sample_no', 'ORDER-1-1/3')
            ->assertJsonPath('data.1.sample_no', 'ORDER-1-2/3')
            ->assertJsonPath('data.2.sample_no', 'ORDER-1-3/3');

        $this->assertDatabaseHas('test_orders', ['id' => $order->id, 'sample_status' => 'received']);
        $this->assertDatabaseCount('sample_flows', 3);
        $this->assertDatabaseHas('sample_flows', ['action_type' => 'receive', 'holder_to' => '样品室']);

        $this->postJsonAs($receiver, '/api/samples/receive', [
            'test_order_id' => $order->id,
            'received_date' => '2026-05-30',
            'current_location' => '样品室 A2',
            'samples' => [
                $this->receiveRow($orderSamples[0], sampleName: '路灯-补样'),
            ],
        ])->assertCreated()
            ->assertJsonPath('data.0.sample_no', 'ORDER-2-1/1');
    }

    public function test_rejected_rows_are_audited_and_do_not_consume_sample_numbers(): void
    {
        $receiver = $this->userWithPermissions(['samples.receive']);
        [$order, $orderSamples] = $this->orderWithSamples('REJECT');

        $this->postJsonAs($receiver, '/api/samples/receive', [
            'test_order_id' => $order->id,
            'received_date' => '2026-05-29',
            'current_location' => '样品室 A1',
            'samples' => [
                $this->receiveRow($orderSamples[0], sampleName: '路灯-1'),
                $this->receiveRow($orderSamples[1], sampleName: '破损控制器', rejectReason: '外观破损'),
                $this->receiveRow($orderSamples[1], sampleName: '控制器-1'),
            ],
        ])->assertCreated()
            ->assertJsonPath('meta.delivery_received_count', 2)
            ->assertJsonPath('meta.rejected_count', 1)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.sample_no', 'REJECT-1-1/2')
            ->assertJsonPath('data.1.sample_no', 'REJECT-1-2/2');

        $this->assertDatabaseMissing('samples', ['sample_name' => '破损控制器']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'samples.receive.rejected', 'module' => 'samples']);
        $this->assertDatabaseCount('sample_flows', 2);
    }

    public function test_sample_receive_order_list_denial_names_missing_permission(): void
    {
        $receiver = $this->userWithPermissions(['samples.receive']);

        $this->getJsonAs($receiver, '/api/test-orders')
            ->assertForbidden()
            ->assertJsonPath('message', 'Forbidden')
            ->assertJsonPath('permission', 'test_orders.read');
    }

    public function test_receiver_can_load_minimal_receive_options_without_test_order_read_permission(): void
    {
        $receiver = $this->userWithPermissions(['samples.receive']);
        [$order] = $this->orderWithSamples('RECV-OPTIONS');
        EquipmentLocation::query()->create([
            'name' => '样品室',
            'code' => 'sample-room',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $this->getJsonAs($receiver, '/api/samples/receive-options')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.order_no', 'RECV-OPTIONS')
            ->assertJsonMissingPath('data.0.samples')
            ->assertJsonPath('meta.locations.0.name', '样品室')
            ->assertJsonPath('meta.locations.0.label', '样品室');
    }

    private function orderWithSamples(string $orderNo): array
    {
        $order = TestOrder::query()->create([
            'order_no' => $orderNo,
            'contract_no' => $orderNo,
            'order_date' => '2026-05-29',
            'urgency' => 'normal',
            'client_company' => '中山市XXX有限公司',
            'sample_status' => 'not_received',
        ]);

        return [
            $order,
            [
                TestOrderSample::query()->create([
                    'test_order_id' => $order->id,
                    'sample_name' => '路灯',
                    'specification' => 'LD',
                    'model' => 'LD-100',
                    'status' => 'pending',
                    'quantity' => 1,
                ]),
                TestOrderSample::query()->create([
                    'test_order_id' => $order->id,
                    'sample_name' => '控制器',
                    'specification' => 'CTRL',
                    'model' => 'C-1',
                    'status' => 'pending',
                    'quantity' => 1,
                ]),
            ],
        ];
    }

    private function receiveRow(TestOrderSample $orderSample, ?string $sampleName = null, ?string $rejectReason = null): array
    {
        return [
            'test_order_sample_id' => $orderSample->id,
            'sample_name' => $sampleName ?? $orderSample->sample_name,
            'specification' => $orderSample->specification,
            'model' => $orderSample->model,
            'appearance_check' => '外观完整',
            'reject_reason' => $rejectReason,
        ];
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'sample_receive_'.str()->random(8), 'guard_name' => 'web']);
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

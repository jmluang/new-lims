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

    public function test_receive_samples_preserves_order_sample_remark(): void
    {
        $receiver = $this->userWithPermissions(['samples.receive', 'samples.read']);
        [$order, $orderSamples] = $this->orderWithSamples('REMARK');

        $orderSamples[0]->update(['remark' => '客户备注：需保留原包装']);

        $this->getJsonAs($receiver, "/api/test-orders/{$order->id}/sample-options")
            ->assertOk()
            ->assertJsonPath('data.samples.0.remark', '客户备注：需保留原包装');

        $this->postJsonAs($receiver, '/api/samples/receive', [
            'test_order_id' => $order->id,
            'received_date' => '2026-05-29',
            'current_location' => '样品室 A1',
            'samples' => [
                $this->receiveRow($orderSamples[0]),
            ],
        ])->assertCreated()
            ->assertJsonPath('data.0.remark', '客户备注：需保留原包装');

        $this->assertDatabaseHas('samples', [
            'test_order_sample_id' => $orderSamples[0]->id,
            'remark' => '客户备注：需保留原包装',
        ]);
    }

    public function test_receive_samples_copies_actual_electrical_values(): void
    {
        $receiver = $this->userWithPermissions(['samples.receive', 'samples.read']);
        [$order, $orderSamples] = $this->orderWithSamples('ELECTRICAL');

        $orderSamples[0]->update([
            'input_voltage' => '220V',
            'rated_current' => '1.3A',
            'rated_frequency' => '50Hz',
            'power' => '300W',
        ]);

        $this->getJsonAs($receiver, "/api/test-orders/{$order->id}/sample-options")
            ->assertOk()
            ->assertJsonPath('data.samples.0.input_voltage', '220V')
            ->assertJsonPath('data.samples.0.rated_current', '1.3A')
            ->assertJsonPath('data.samples.0.rated_frequency', '50Hz')
            ->assertJsonPath('data.samples.0.power', '300W');

        $this->postJsonAs($receiver, '/api/samples/receive', [
            'test_order_id' => $order->id,
            'received_date' => '2026-05-29',
            'current_location' => '样品室 A1',
            'samples' => [
                $this->receiveRow($orderSamples[0]),
            ],
        ])->assertCreated()
            ->assertJsonPath('data.0.input_voltage', '220V')
            ->assertJsonPath('data.0.rated_current', '1.3A')
            ->assertJsonPath('data.0.rated_frequency', '50Hz')
            ->assertJsonPath('data.0.power', '300W');

        $this->assertDatabaseHas('samples', [
            'test_order_sample_id' => $orderSamples[0]->id,
            'input_voltage' => '220V',
            'rated_current' => '1.3A',
            'rated_frequency' => '50Hz',
            'power' => '300W',
        ]);
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

    public function test_partial_delivery_does_not_mark_the_entire_order_received(): void
    {
        $receiver = $this->userWithPermissions(['samples.receive']);
        [$order, $orderSamples] = $this->orderWithSamples('PARTIAL-DELIVERY');

        $this->postJsonAs($receiver, '/api/samples/receive', [
            'test_order_id' => $order->id,
            'current_location' => 'Sample room A1',
            'samples' => [$this->receiveRow($orderSamples[0])],
        ])->assertCreated();
        $this->assertDatabaseHas('test_order_samples', ['id' => $orderSamples[1]->id, 'status' => 'pending']);
        $options = $this->getJsonAs($receiver, '/api/samples/receive-options')->assertOk()->json('data');
        $this->assertSame([
            'status' => 'partially_received',
            'selectable_order_ids' => [$order->id],
        ], [
            'status' => $order->fresh()->sample_status,
            'selectable_order_ids' => array_column($options, 'id'),
        ]);
    }

    public function test_split_quantity_deliveries_offer_only_the_remainder_until_complete(): void
    {
        $receiver = $this->userWithPermissions(['samples.receive']);
        [$order, $orderSamples] = $this->orderWithSamples('SPLIT-DELIVERY');
        $orderSamples[0]->update(['quantity' => 3]);
        $orderSamples[1]->update(['status' => 'cancelled']);
        $payload = [
            'test_order_id' => $order->id,
            'current_location' => 'Sample room A1',
            'samples' => [$this->receiveRow($orderSamples[0])],
        ];

        $this->postJsonAs($receiver, '/api/samples/receive', $payload)->assertCreated();
        $this->assertSame('partially_received', $orderSamples[0]->fresh()->status);
        $this->assertSame('partially_received', $order->fresh()->sample_status);
        $this->getJsonAs($receiver, "/api/test-orders/{$order->id}/sample-options")
            ->assertOk()
            ->assertJsonPath('data.samples.0.quantity', 3)
            ->assertJsonPath('data.samples.0.remaining_quantity', 2)
            ->assertJsonPath('data.samples.1.remaining_quantity', 0);

        $payload['samples'][] = $this->receiveRow($orderSamples[0]);
        $this->postJsonAs($receiver, '/api/samples/receive', $payload)->assertCreated();
        $this->assertSame('received', $orderSamples[0]->fresh()->status);
        $this->assertSame('received', $order->fresh()->sample_status);
        $this->getJsonAs($receiver, "/api/test-orders/{$order->id}/sample-options")
            ->assertOk()->assertJsonPath('data.samples.0.remaining_quantity', 0);
        $this->getJsonAs($receiver, '/api/samples/receive-options')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_supplemental_delivery_preserves_later_order_progress(): void
    {
        $receiver = $this->userWithPermissions(['samples.receive']);
        [$order, $orderSamples] = $this->orderWithSamples('SUPPLEMENTAL-DELIVERY');
        $order->update(['sample_status' => 'testing']);
        $this->postJsonAs($receiver, '/api/samples/receive', [
            'test_order_id' => $order->id,
            'current_location' => 'Sample room A1',
            'samples' => [$this->receiveRow($orderSamples[0])],
        ])->assertCreated();
        $this->assertSame('testing', $order->fresh()->sample_status);
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

    private function receiveRow(TestOrderSample $orderSample, ?string $sampleName = null, ?string $rejectReason = null, ?string $remark = null): array
    {
        $row = [
            'test_order_sample_id' => $orderSample->id,
            'sample_name' => $sampleName ?? $orderSample->sample_name,
            'specification' => $orderSample->specification,
            'model' => $orderSample->model,
            'input_voltage' => $orderSample->input_voltage,
            'rated_current' => $orderSample->rated_current,
            'rated_frequency' => $orderSample->rated_frequency,
            'power' => $orderSample->power,
            'appearance_check' => '外观完整',
            'reject_reason' => $rejectReason,
        ];

        if ($remark !== null) {
            $row['remark'] = $remark;
        }

        return $row;
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

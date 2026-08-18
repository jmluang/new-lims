<?php

namespace Tests\Feature\TestOrders;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Standard;
use App\Models\TestOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TestOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_can_create_show_filter_and_export_test_order_with_child_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00'));
        $admin = $this->userWithPermissions($this->managerPermissions(['test_orders.export']));
        $customer = Customer::query()->create([
            'name' => '中山市XXX有限公司',
            'address' => '中山市古镇镇古一飞虎楼8栋2层201室',
            'phone' => '1388888888',
            'email' => 'customer@example.test',
            'status' => 'active',
        ]);
        $standard = $this->standard();

        $orderId = $this->postJsonAs($admin, '/api/test-orders', $this->payload($customer, $standard))
            ->assertCreated()
            ->assertJsonPath('data.order_no', '26000015738')
            ->assertJsonPath('data.contract_no', '26000015738')
            ->assertJsonPath('data.client_customer_id', $customer->id)
            ->assertJsonPath('data.client_company', '中山市XXX有限公司')
            ->assertJsonPath('data.client_email', 'client@example.test')
            ->assertJsonPath('data.manufacturer_email', 'manufacturer@example.test')
            ->assertJsonPath('data.maker_email', 'maker@example.test')
            ->assertJsonPath('data.sample_return', 'return')
            ->assertJsonPath('data.shipping_notes', 'Please keep original packaging.')
            ->assertJsonCount(1, 'data.standards')
            ->assertJsonCount(2, 'data.samples')
            ->assertJsonPath('data.samples.0.rated_current', '1.3A')
            ->assertJsonPath('data.samples.0.rated_frequency', '50Hz')
            ->assertJsonPath('data.samples.0.quantity_unit', '个')
            ->assertJsonPath('data.samples.0.sample_condition', 'good')
            ->json('data.id');

        $this->getJsonAs($admin, "/api/test-orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.order_no', '26000015738')
            ->assertJsonPath('data.standards.0.standard_code', 'GB/T 7000.1-2023')
            ->assertJsonPath('data.samples.0.sample_name', '路灯');

        $this->getJsonAs($admin, '/api/test-orders?search=26000015738&sample_status=not_received&client_customer_id='.$customer->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orderId);

        $this->getJsonAs($admin, '/api/test-orders/export?client_customer_id='.$customer->id)
            ->assertOk()
            ->assertJsonPath('headers.0', 'order_no')
            ->assertJsonPath('data.0.order_no', '26000015738');
    }

    public function test_update_syncs_child_rows_by_id_without_delete_and_reinsert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00'));
        $admin = $this->userWithPermissions($this->managerPermissions());
        $customer = Customer::query()->create(['name' => '中山市XXX有限公司', 'status' => 'active']);
        $standard = $this->standard();
        $order = $this->postJsonAs($admin, '/api/test-orders', $this->payload($customer, $standard))->assertCreated()->json('data');
        $keptStandardId = $order['standards'][0]['id'];
        $removedSampleId = $order['samples'][0]['id'];
        $keptSampleId = $order['samples'][1]['id'];

        $this->putJsonAs($admin, "/api/test-orders/{$order['id']}", [
            'client_company' => '中山市XXX有限公司',
            'sample_status' => 'not_received',
            'standards' => [
                [
                    'id' => $keptStandardId,
                    'standard_id' => $standard->id,
                    'standard_code' => 'GB/T 7000.1-2023',
                    'standard_name' => '灯具 第1部分：一般要求与试验',
                    'report_language' => 'zh',
                    'qualifications' => ['CMA'],
                    'requirement' => "接地电阻\n绝缘电阻\n耐压测试\n泄漏电流",
                ],
                [
                    'standard_id' => $standard->id,
                    'standard_code' => 'GB/T 7000.1-2023',
                    'standard_name' => '灯具 第1部分：一般要求与试验',
                    'report_language' => 'en',
                    'qualifications' => [],
                    'requirement' => 'Photometric test',
                ],
            ],
            'samples' => [
                [
                    'id' => $keptSampleId,
                    'sample_name' => '控制器',
                    'specification' => 'CTRL',
                    'model' => 'C-1',
                    'status' => 'pending',
                    'quantity' => 1,
                    'detail_content' => '功能检查（更新）',
                ],
                [
                    'sample_name' => '电源',
                    'specification' => 'PSU',
                    'model' => 'P-1',
                    'status' => 'pending',
                    'quantity' => 2,
                    'detail_content' => '输入输出检查',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.standards.0.id', $keptStandardId)
            ->assertJsonPath('data.samples.0.id', $keptSampleId)
            ->assertJsonCount(2, 'data.standards')
            ->assertJsonCount(2, 'data.samples');

        $this->assertDatabaseHas('test_order_standards', ['id' => $keptStandardId, 'requirement' => "接地电阻\n绝缘电阻\n耐压测试\n泄漏电流"]);
        $this->assertDatabaseMissing('test_order_samples', ['id' => $removedSampleId]);
        $this->assertDatabaseHas('test_order_samples', ['id' => $keptSampleId, 'detail_content' => '功能检查（更新）']);
    }

    public function test_order_history_lists_the_actor_and_changed_fields_for_an_order(): void
    {
        $admin = $this->userWithPermissions($this->managerPermissions());
        $customer = Customer::query()->create(['name' => '中山市XXX有限公司', 'status' => 'active']);
        $standard = $this->standard();
        $orderId = $this->postJsonAs($admin, '/api/test-orders', $this->payload($customer, $standard))->assertCreated()->json('data.id');

        $this->putJsonAs($admin, "/api/test-orders/{$orderId}", ['remark' => '客户要求加急处理'])
            ->assertOk();

        $this->getJsonAs($admin, "/api/test-orders/{$orderId}/history")
            ->assertOk()
            ->assertJsonPath('data.0.actor_user_id', $admin->id)
            ->assertJsonPath('data.0.actor_name', $admin->name)
            ->assertJsonPath('data.0.changes.0.field', '备注')
            ->assertJsonPath('data.0.changes.0.old_value', null)
            ->assertJsonPath('data.0.changes.0.new_value', '客户要求加急处理');
    }

    public function test_test_order_list_defaults_to_newest_id_first(): void
    {
        $reader = $this->userWithPermissions(['test_orders.read']);
        $older = TestOrder::query()->create([
            'order_no' => 'ORDER-OLD',
            'contract_no' => 'CONTRACT-OLD',
            'order_date' => '2026-05-07',
            'urgency' => 'normal',
            'client_company' => '中山市旧委托客户',
            'sample_status' => 'not_received',
        ]);
        $newer = TestOrder::query()->create([
            'order_no' => 'ORDER-NEW',
            'contract_no' => 'CONTRACT-NEW',
            'order_date' => '2026-05-08',
            'urgency' => 'normal',
            'client_company' => '中山市新委托客户',
            'sample_status' => 'not_received',
        ]);

        $this->getJsonAs($reader, '/api/test-orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_sample_options_requires_receive_permission_and_returns_minimal_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07 09:00:00'));
        $orderManager = $this->userWithPermissions($this->managerPermissions());
        $receiver = $this->userWithPermissions(['samples.receive']);
        $customer = Customer::query()->create(['name' => '中山市XXX有限公司', 'status' => 'active']);
        $standard = $this->standard();
        $orderId = $this->postJsonAs($orderManager, '/api/test-orders', $this->payload($customer, $standard))->assertCreated()->json('data.id');

        $this->getJsonAs($orderManager, "/api/test-orders/{$orderId}/sample-options")
            ->assertForbidden();

        $this->getJsonAs($receiver, "/api/test-orders/{$orderId}/sample-options")
            ->assertOk()
            ->assertJsonPath('data.order.id', $orderId)
            ->assertJsonCount(2, 'data.samples')
            ->assertJsonPath('data.samples.0.sample_name', '路灯')
            ->assertJsonMissingPath('data.samples.0.detail_content');
    }

    public function test_form_options_return_customers_contacts_and_standards_without_customer_read_permission(): void
    {
        $manager = $this->userWithPermissions(['test_orders.create']);
        $customer = Customer::query()->create([
            'name' => '中山市星河检测客户',
            'credit_code' => '91442000MA7TEST',
            'phone' => '0760-88886666',
            'address' => '中山市古镇镇',
            'status' => 'active',
        ]);
        CustomerContact::query()->create([
            'customer_id' => $customer->id,
            'name' => '客户管理人A',
            'phone' => '13900000000',
            'is_default' => true,
            'status' => 'active',
        ]);
        $standard = $this->standard();

        $this->getJsonAs($manager, '/api/customers')->assertForbidden();

        $this->getJsonAs($manager, '/api/test-orders/form-options')
            ->assertOk()
            ->assertJsonPath('data.customers.0.id', $customer->id)
            ->assertJsonPath('data.customers.0.name', '中山市星河检测客户')
            ->assertJsonPath('data.customers.0.contacts.0.name', '客户管理人A')
            ->assertJsonPath('data.customers.0.default_contact.name', '客户管理人A')
            ->assertJsonPath('data.standards.0.id', $standard->id);
    }

    private function payload(Customer $customer, Standard $standard): array
    {
        return [
            'order_date' => '2026-05-07',
            'urgency' => 'normal',
            'client_customer_id' => $customer->id,
            'client_company' => $customer->name,
            'client_address' => $customer->address,
            'client_contact' => '唐僧',
            'client_phone' => '1388888888',
            'client_email' => 'client@example.test',
            'manufacturer_company' => '中山市制造有限公司',
            'manufacturer_email' => 'manufacturer@example.test',
            'maker_company' => '中山市制造有限公司',
            'maker_email' => 'maker@example.test',
            'report_forms' => ['electronic', 'paper'],
            'sample_return' => 'return',
            'outsourcing_option' => 'allowed',
            'address_lab_name' => '样品室',
            'address_contact' => '张三',
            'address_detail' => '中山实验室',
            'address_phone' => '13900000000',
            'shipping_notes' => 'Please keep original packaging.',
            'standards' => [
                [
                    'standard_id' => $standard->id,
                    'standard_code' => $standard->std_no,
                    'standard_name' => $standard->chinese_name,
                    'report_language' => 'zh',
                    'qualifications' => ['CMA'],
                    'requirement' => "接地电阻\n绝缘电阻\n耐压测试",
                ],
            ],
            'samples' => [
                [
                    'sample_name' => '路灯',
                    'specification' => 'LD',
                    'model' => 'LD-100',
                    'input_voltage' => '220V',
                    'rated_current' => '1.3A',
                    'power' => '300W',
                    'rated_frequency' => '50Hz',
                    'status' => 'pending',
                    'quantity' => 3,
                    'quantity_unit' => '个',
                    'sample_condition' => 'good',
                    'sample_condition_note' => null,
                    'detail_content' => "电压\n电流\n功率",
                ],
                [
                    'sample_name' => '控制器',
                    'specification' => 'CTRL',
                    'model' => 'C-1',
                    'input_voltage' => '12V',
                    'rated_current' => '0.5A',
                    'power' => '10W',
                    'rated_frequency' => '50Hz',
                    'status' => 'pending',
                    'quantity' => 1,
                    'quantity_unit' => '个',
                    'sample_condition' => 'good',
                    'detail_content' => '功能检查',
                ],
            ],
        ];
    }

    private function standard(): Standard
    {
        return Standard::query()->create([
            'std_no' => 'GB/T 7000.1-2023',
            'chinese_name' => '灯具 第1部分：一般要求与试验',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<int, string>  $extra
     * @return array<int, string>
     */
    private function managerPermissions(array $extra = []): array
    {
        return [
            'test_orders.read',
            'test_orders.create',
            'test_orders.update',
            'test_orders.delete',
            'test_order_standards.read',
            'test_order_standards.create',
            'test_order_standards.update',
            'test_order_standards.delete',
            'test_order_samples.read',
            'test_order_samples.create',
            'test_order_samples.update',
            'test_order_samples.delete',
            ...$extra,
        ];
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_orders_'.str()->random(8), 'guard_name' => 'web']);
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

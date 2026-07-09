<?php

namespace Tests\Feature\TestOrders;

use App\Models\Customer;
use App\Models\Standard;
use App\Models\TestOrder;
use App\Models\TestOrderSample;
use App\Models\TestOrderStandard;
use App\Models\User;
use App\Services\Pdf\PdfRendererClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TestOrderEntrustOrderPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_pdf_endpoint_requires_print_permission(): void
    {
        $viewer = $this->userWithPermissions(['test_orders.read']);
        $order = $this->createCompleteTestOrder();

        Sanctum::actingAs($viewer);

        $this->getJson("/api/test-orders/{$order->id}/entrust-order.pdf")
            ->assertForbidden()
            ->assertJsonPath('permission', 'test_orders.print');
    }

    public function test_pdf_endpoint_maps_test_order_to_renderer_payload_and_returns_pdf(): void
    {
        $printer = $this->userWithPermissions(['test_orders.read', 'test_orders.print']);
        $order = $this->createCompleteTestOrder();

        $client = Mockery::mock(PdfRendererClient::class);
        $client->shouldReceive('renderEntrustOrder')
            ->once()
            ->with(Mockery::on(function (array $payload) use ($order): bool {
                return $payload['base']['entrust_number'] === $order->order_no
                    && $payload['base']['urgency']['value'] === 'critical'
                    && $payload['base']['urgency']['label'] === '特急'
                    && collect($payload['base']['urgency_options'])->contains(
                        fn (array $option): bool => $option['value'] === 'critical' && $option['label'] === '特急'
                    )
                    && $payload['client']['company_name'] === '中山市铭宜镁照明科技有限公司'
                    && $payload['client']['email'] === 'client@example.test'
                    && $payload['requirements']['sample_return']['value'] === 'return'
                    && collect($payload['requirements']['report_forms'])->contains(
                        fn (array $option): bool => $option['value'] === 'electronic' && $option['label'] === '电子档'
                    )
                    && collect($payload['requirements']['report_forms'])->contains(
                        fn (array $option): bool => $option['value'] === 'paper' && $option['label'] === '纸本'
                    )
                    && $payload['requirements']['standards'][0]['notes'] === null
                    && count($payload['samples']) === 2
                    && $payload['samples'][0]['current'] === '1.3A'
                    && $payload['samples'][0]['frequency'] === '50Hz'
                    && $payload['samples'][0]['quantity_unit'] === '个'
                    && $payload['samples'][0]['condition']['value'] === 'good'
                    && $payload['samples'][0]['condition']['label'] === '完好'
                    && $payload['logistics']['shipping_notes'] === 'Please keep original packaging.'
                    && $payload['signatures']['client_signature_name'] === '唐僧';
            }))
            ->andReturn('%PDF-1.4 fake pdf');
        $this->app->instance(PdfRendererClient::class, $client);

        Sanctum::actingAs($printer);

        $this->get("/api/test-orders/{$order->id}/entrust-order.pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename='.$order->order_no.'.pdf')
            ->assertSee('%PDF-1.4 fake pdf', false);
    }

    public function test_pdf_endpoint_returns_bad_gateway_when_renderer_fails(): void
    {
        $printer = $this->userWithPermissions(['test_orders.read', 'test_orders.print']);
        $order = $this->createCompleteTestOrder();

        $client = Mockery::mock(PdfRendererClient::class);
        $client->shouldReceive('renderEntrustOrder')->andThrow(new RuntimeException('PDF service returned HTTP 500.'));
        $this->app->instance(PdfRendererClient::class, $client);

        Sanctum::actingAs($printer);

        $this->getJson("/api/test-orders/{$order->id}/entrust-order.pdf")
            ->assertStatus(502)
            ->assertJsonPath('message', 'Unable to generate entrust order PDF.');
    }

    private function createCompleteTestOrder(): TestOrder
    {
        $customer = Customer::query()->create([
            'name' => '中山市铭宜镁照明科技有限公司',
            'address' => '中山古镇曹兴西路117号',
            'phone' => '1388888888',
            'email' => 'client@example.test',
            'status' => 'active',
        ]);
        $standard = Standard::query()->create([
            'std_no' => 'GB/T 9468-2008',
            'chinese_name' => '灯具分布光度测量的一般要求',
            'status' => 'active',
        ]);

        $order = TestOrder::query()->create([
            'order_no' => '2026050001',
            'contract_no' => '2026050001',
            'order_date' => '2026-05-08',
            'planned_end_date' => '2026-05-11',
            'urgency' => 'critical',
            'client_customer_id' => $customer->id,
            'client_company' => $customer->name,
            'client_address' => $customer->address,
            'client_contact' => '唐僧',
            'client_phone' => $customer->phone,
            'client_email' => $customer->email,
            'manufacturer_company' => '中山市制造有限公司',
            'manufacturer_email' => 'manufacturer@example.test',
            'maker_company' => '中山市生产有限公司',
            'maker_email' => 'maker@example.test',
            'report_forms' => ['electronic', 'paper'],
            'sample_return' => 'return',
            'delivery_method' => 'mail',
            'outsourcing_option' => 'not_allowed',
            'remark' => 'Keep original test-order remark.',
            'sample_status' => 'not_received',
            'address_lab_name' => '中山实验室',
            'address_contact' => '张三',
            'address_detail' => '中山市实验室地址',
            'address_phone' => '13900000000',
            'shipping_notes' => 'Please keep original packaging.',
            'client_signature' => '唐僧',
            'client_sign_date' => '2026-05-08',
            'dept_confirm' => '综合部A',
            'dept_confirm_date' => '2026-05-08',
            'lab_confirm' => '检测部B',
            'lab_confirm_date' => '2026-05-09',
        ]);

        TestOrderStandard::query()->create([
            'test_order_id' => $order->id,
            'standard_id' => $standard->id,
            'standard_code' => $standard->std_no,
            'standard_name' => $standard->chinese_name,
            'report_language' => 'zh',
            'qualifications' => ['CNAS', 'CMA'],
            'requirement' => "接地电阻\n绝缘电阻",
            'sort_order' => 0,
        ]);

        TestOrderSample::query()->create([
            'test_order_id' => $order->id,
            'sample_name' => 'LED模组路灯头',
            'specification' => 'LD',
            'model' => 'MYM-300',
            'input_voltage' => '220V',
            'rated_current' => '1.3A',
            'power' => '300W',
            'rated_frequency' => '50Hz',
            'status' => 'pending',
            'quantity' => 1,
            'quantity_unit' => '个',
            'sample_condition' => 'good',
            'sample_condition_note' => null,
            'detail_content' => '光度测试',
            'remark' => 'No visible damage.',
            'sort_order' => 0,
        ]);

        TestOrderSample::query()->create([
            'test_order_id' => $order->id,
            'sample_name' => 'LED模组天花灯头',
            'specification' => 'TH',
            'model' => 'MYM-300',
            'input_voltage' => '220V',
            'rated_current' => '1.3A',
            'power' => '300W',
            'rated_frequency' => '50Hz',
            'status' => 'pending',
            'quantity' => 1,
            'quantity_unit' => '个',
            'sample_condition' => 'good',
            'detail_content' => '光度测试',
            'sort_order' => 1,
        ]);

        return $order->fresh(['standards', 'samples']);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_orders_pdf_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

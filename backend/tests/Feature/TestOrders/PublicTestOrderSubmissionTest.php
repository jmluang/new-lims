<?php

namespace Tests\Feature\TestOrders;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicTestOrderSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_public_customer_lookup_matches_customer_contact_phone(): void
    {
        $customer = Customer::query()->create([
            'name' => '中山市星河照明有限公司',
            'address' => '中山市古镇镇星河路 1 号',
            'phone' => '0760-88886666',
            'status' => 'active',
        ]);
        $customer->contacts()->create([
            'name' => '唐小姐',
            'phone' => '13800000000',
            'is_default' => true,
            'status' => 'active',
        ]);

        $this->postJson('/api/public/test-order-submissions/customer-lookup', ['phone' => '13800000000'])
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonPath('data.name', '中山市星河照明有限公司')
            ->assertJsonPath('data.address', '中山市古镇镇星河路 1 号')
            ->assertJsonPath('data.contact.name', '唐小姐');
    }

    public function test_public_customer_can_submit_test_order_without_authentication(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 10:00:00'));
        $customer = Customer::query()->create([
            'name' => '中山市星河照明有限公司',
            'address' => '中山市古镇镇星河路 1 号',
            'phone' => '0760-88886666',
            'status' => 'active',
        ]);
        $customer->contacts()->create([
            'name' => '唐小姐',
            'phone' => '13800000000',
            'is_default' => true,
            'status' => 'active',
        ]);

        $this->postJson('/api/public/test-order-submissions', [
            'client_company' => '中山市星河照明有限公司',
            'client_address' => '中山市古镇镇星河路 1 号',
            'client_contact' => '唐小姐',
            'client_phone' => '13800000000',
            'samples' => [
                [
                    'sample_name' => '路灯',
                    'specification' => 'LD',
                    'model' => 'LD-100',
                    'input_voltage' => 'AC 220V',
                    'power' => '100W',
                ],
                [
                    'sample_name' => '控制器',
                    'specification' => 'CTRL',
                    'model' => 'C-1',
                    'input_voltage' => 'DC 12V',
                    'power' => '10W',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.client_company', '中山市星河照明有限公司')
            ->assertJsonPath('data.samples_count', 2);

        $this->assertDatabaseHas('test_orders', [
            'client_customer_id' => $customer->id,
            'client_company' => '中山市星河照明有限公司',
            'client_contact' => '唐小姐',
            'client_phone' => '13800000000',
            'sample_status' => 'not_received',
        ]);
        $this->assertDatabaseHas('test_order_samples', [
            'sample_name' => '路灯',
            'input_voltage' => 'AC 220V',
            'power' => '100W',
        ]);
    }
}

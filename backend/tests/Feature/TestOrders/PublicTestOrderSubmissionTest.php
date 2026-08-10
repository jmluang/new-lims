<?php

namespace Tests\Feature\TestOrders;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PublicTestOrderSubmissionTest extends TestCase
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

    public function test_public_customer_lookup_does_not_reveal_customer_existence_or_details(): void
    {
        $this->customer();

        $this->postJson('/api/public/test-order-submissions/customer-lookup', ['phone' => '13800000000'])
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonMissingPath('data.matched')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.name')
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.phone')
            ->assertJsonMissingPath('data.contact');
    }

    public function test_public_customer_submission_creates_pending_submission_without_official_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 10:00:00'));
        $customer = $this->customer();

        $this->postJson('/api/public/test-order-submissions', $this->payload())->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.client_company', '中山市星河照明有限公司')
            ->assertJsonPath('data.samples_count', 2)
            ->assertJsonMissingPath('data.order_no');

        $this->assertDatabaseCount('test_orders', 0);
        $this->assertDatabaseCount('test_order_samples', 0);
        $this->assertDatabaseHas('public_test_order_submissions', [
            'matched_customer_id' => $customer->id,
            'client_company' => '中山市星河照明有限公司',
            'client_contact' => '唐小姐',
            'client_phone' => '13800000000',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'public_test_order_submissions.create',
            'module' => 'public_test_order_submissions',
        ]);
    }

    public function test_review_acceptance_converts_pending_submission_to_official_test_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 10:00:00'));
        $this->customer();

        $submissionId = $this->postJson('/api/public/test-order-submissions', $this->payload())
            ->assertCreated()
            ->json('data.id');
        $reviewer = $this->userWithPermissions(['test_orders.read', 'test_orders.create']);

        $this->postJsonAs($reviewer, "/api/public-test-order-submissions/{$submissionId}/accept")
            ->assertCreated()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.test_order.client_company', '中山市星河照明有限公司')
            ->assertJsonPath('data.test_order.address_lab_name', '中山市鑫普达检测有限公司')
            ->assertJsonPath('data.test_order.address_contact', '鑫普达检测')
            ->assertJsonPath('data.test_order.address_detail', '广东省中山市古镇镇东兴东路33号7栋1层之一')
            ->assertJsonPath('data.test_order.samples.0.input_voltage', 'AC 220V');

        $this->assertDatabaseHas('public_test_order_submissions', [
            'id' => $submissionId,
            'status' => 'accepted',
            'accepted_by' => $reviewer->id,
        ]);
        $this->assertDatabaseHas('test_orders', [
            'client_company' => '中山市星河照明有限公司',
            'client_contact' => '唐小姐',
            'client_phone' => '13800000000',
            'sample_status' => 'not_received',
            'address_lab_name' => '中山市鑫普达检测有限公司',
            'address_contact' => '鑫普达检测',
            'address_detail' => '广东省中山市古镇镇东兴东路33号7栋1层之一',
        ]);
        $this->assertDatabaseHas('test_order_samples', [
            'sample_name' => '路灯',
            'input_voltage' => 'AC 220V',
            'power' => '100W',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'public_test_order_submissions.accept',
            'module' => 'public_test_order_submissions',
            'actor_user_id' => $reviewer->id,
        ]);
    }

    public function test_public_submission_rejects_unbounded_sample_rows(): void
    {
        $payload = $this->payload([
            'samples' => array_fill(0, 21, [
                'sample_name' => '路灯',
                'specification' => 'LD',
                'model' => 'LD-100',
                'input_voltage' => 'AC 220V',
                'power' => '100W',
            ]),
        ]);

        $this->postJson('/api/public/test-order-submissions', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('samples');
    }

    private function customer(): Customer
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

        return $customer;
    }

    private function payload(array $overrides = []): array
    {
        return [
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
            ...$overrides,
        ];
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'public_submission_reviewer_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function postJsonAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->postJson($uri, $data);
    }
}

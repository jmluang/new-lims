<?php

namespace Tests\Feature\TestOrders;

use App\Models\TestOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TestOrderMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_test_order_message_push_requires_notify_permission(): void
    {
        $sender = $this->userWithPermissions(['test_orders.read']);
        $recipient = User::factory()->create(['name' => 'Reviewer']);
        $testOrder = $this->testOrder();

        $this->postJsonAs($sender, "/api/test-orders/{$testOrder->id}/messages", [
            'recipient_user_id' => $recipient->id,
        ])->assertForbidden()
            ->assertJsonPath('permission', 'test_orders.notify');
    }

    public function test_user_with_notify_permission_can_push_test_order_message_to_backend_user(): void
    {
        $sender = $this->userWithPermissions(['test_orders.read', 'test_orders.notify']);
        $recipient = User::factory()->create(['name' => 'Reviewer', 'status' => 'active']);
        $testOrder = $this->testOrder(['order_no' => 'WT-26000001']);

        $this->postJsonAs($sender, "/api/test-orders/{$testOrder->id}/messages", [
            'recipient_user_id' => $recipient->id,
        ])->assertCreated()
            ->assertJsonPath('data.recipient.id', $recipient->id)
            ->assertJsonPath('data.sender.id', $sender->id)
            ->assertJsonPath('data.test_order.id', $testOrder->id)
            ->assertJsonPath('data.title', '委托试验单处理提醒')
            ->assertJsonPath('data.content', '请及时处理委托试验单 WT-26000001。')
            ->assertJsonPath('data.read_at', null);

        $this->assertDatabaseHas('user_messages', [
            'recipient_user_id' => $recipient->id,
            'sender_user_id' => $sender->id,
            'test_order_id' => $testOrder->id,
            'title' => '委托试验单处理提醒',
            'content' => '请及时处理委托试验单 WT-26000001。',
        ]);
    }

    public function test_notify_permission_can_list_active_message_recipients(): void
    {
        $sender = $this->userWithPermissions(['test_orders.notify']);
        $activeUser = User::factory()->create(['name' => 'Active Reviewer', 'email' => 'active-reviewer@example.test', 'status' => 'active']);
        User::factory()->create(['name' => 'Disabled Reviewer', 'email' => 'disabled-reviewer@example.test', 'status' => 'disabled']);

        $this->getJsonAs($sender, '/api/test-orders/message-recipients')
            ->assertOk()
            ->assertJsonPath('data.0.id', $activeUser->id)
            ->assertJsonPath('data.0.name', 'Active Reviewer')
            ->assertJsonPath('data.0.email', 'active-reviewer@example.test')
            ->assertJsonCount(1, 'data');

        $this->getJsonAs($this->userWithPermissions(['test_orders.read']), '/api/test-orders/message-recipients')
            ->assertForbidden()
            ->assertJsonPath('permission', 'test_orders.notify');
    }

    public function test_recipient_lists_only_own_messages_and_marks_message_read(): void
    {
        $sender = $this->userWithPermissions(['test_orders.read', 'test_orders.notify']);
        $recipient = User::factory()->create(['name' => 'Recipient']);
        $otherRecipient = User::factory()->create(['name' => 'Other Recipient']);
        $testOrder = $this->testOrder(['order_no' => 'WT-26000002']);
        $otherOrder = $this->testOrder(['order_no' => 'WT-26000003']);

        $messageId = $this->postJsonAs($sender, "/api/test-orders/{$testOrder->id}/messages", [
            'recipient_user_id' => $recipient->id,
        ])->assertCreated()->json('data.id');
        $this->postJsonAs($sender, "/api/test-orders/{$otherOrder->id}/messages", [
            'recipient_user_id' => $otherRecipient->id,
        ])->assertCreated();

        $this->getJsonAs($recipient, '/api/messages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $messageId)
            ->assertJsonPath('data.0.test_order.order_no', 'WT-26000002')
            ->assertJsonPath('meta.unread_count', 1);

        $this->postJsonAs($otherRecipient, "/api/messages/{$messageId}/read")
            ->assertForbidden();

        $this->postJsonAs($recipient, "/api/messages/{$messageId}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $messageId)
            ->assertJsonPath('data.read', true);

        $this->getJsonAs($recipient, '/api/messages')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0)
            ->assertJsonPath('data.0.read', true);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function testOrder(array $overrides = []): TestOrder
    {
        $orderNo = $overrides['order_no'] ?? 'WT-26000000';

        return TestOrder::query()->create([
            'order_no' => $orderNo,
            'contract_no' => $overrides['contract_no'] ?? $orderNo,
            'order_date' => '2026-06-28',
            'urgency' => 'normal',
            'client_company' => '中山市消息测试客户',
            'sample_status' => 'not_received',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_order_messages_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create(['status' => 'active']);
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

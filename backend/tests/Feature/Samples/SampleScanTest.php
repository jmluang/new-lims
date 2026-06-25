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

class SampleScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_scan_lookup_returns_available_operations_for_pending_sample(): void
    {
        $operator = $this->userWithPermissions(['samples.read', 'sample_flows.create']);
        $sample = $this->receivedSample([
            'sample_no' => 'S-SCAN-001',
            'status' => 'pending',
            'current_holder' => '样品室',
            'current_location' => '样品室',
        ]);

        $this->getJsonAs($operator, '/api/samples/scan-lookup?sample_no=S-SCAN-001')
            ->assertOk()
            ->assertJsonPath('data.sample.id', $sample->id)
            ->assertJsonPath('data.available_actions.0', 'lend');
    }

    public function test_scan_lookup_returns_404_for_unknown_sample(): void
    {
        $operator = $this->userWithPermissions(['samples.read', 'sample_flows.create']);

        $this->getJsonAs($operator, '/api/samples/scan-lookup?sample_no=UNKNOWN')
            ->assertNotFound();
    }

    public function test_scan_lookup_hides_return_room_without_return_room_permission(): void
    {
        $operator = $this->userWithPermissions(['samples.read', 'sample_flows.create']);
        $this->receivedSample([
            'sample_no' => 'S-SCAN-RETURN',
            'status' => 'testing',
            'current_holder' => 'Alice',
            'current_location' => '实验区A',
        ]);

        $this->getJsonAs($operator, '/api/samples/scan-lookup?sample_no=S-SCAN-RETURN')
            ->assertOk()
            ->assertJsonPath('data.available_actions', ['transfer']);
    }

    public function test_scan_lookup_shows_return_room_with_return_room_permission(): void
    {
        $operator = $this->userWithPermissions(['samples.read', 'sample_flows.create', 'sample_flows.return_room']);
        $this->receivedSample([
            'sample_no' => 'S-SCAN-RETURN',
            'status' => 'testing',
            'current_holder' => 'Alice',
            'current_location' => '实验区A',
        ]);

        $this->getJsonAs($operator, '/api/samples/scan-lookup?sample_no=S-SCAN-RETURN')
            ->assertOk()
            ->assertJsonPath('data.available_actions', ['transfer', 'return_room']);
    }

    public function test_scan_flow_records_an_available_action(): void
    {
        Carbon::setTestNow('2026-06-15 12:17:08');
        $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.create']);
        $operator->update(['name' => '扫码操作员']);
        $sample = $this->receivedSample([
            'status' => 'pending',
            'current_holder' => '样品室',
            'current_location' => '样品室',
        ]);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/scan-flow", [
            'action_type' => 'lend',
            'holder_to' => 'Alice',
            'location_to' => '实验区A',
        ])->assertCreated()
            ->assertJsonPath('data.action_type', 'lend')
            ->assertJsonPath('data.holder_to', '扫码操作员')
            ->assertJsonPath('data.action_time', '2026-06-15 12:17:08');

        $this->assertDatabaseHas('samples', [
            'id' => $sample->id,
            'status' => 'testing',
            'current_holder' => '扫码操作员',
        ]);
    }

    public function test_scan_flow_rejects_action_not_available_for_current_state(): void
    {
        $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.create', 'sample_flows.return_room']);
        $sample = $this->receivedSample([
            'status' => 'pending',
            'current_holder' => '样品室',
            'current_location' => '样品室',
        ]);

        // return_room is not an available action for a pending in-room sample.
        $this->postJsonAs($operator, "/api/samples/{$sample->id}/scan-flow", [
            'action_type' => 'return_room',
            'location_to' => '样品室',
        ])->assertStatus(422);

        $this->assertDatabaseCount('sample_flows', 0);
    }

    public function test_scan_flow_rejects_return_room_without_return_room_permission(): void
    {
        $operator = $this->userWithPermissions(['samples.read', 'samples.update', 'sample_flows.create']);
        $sample = $this->receivedSample([
            'status' => 'testing',
            'current_holder' => 'Alice',
            'current_location' => '实验区A',
        ]);

        $this->postJsonAs($operator, "/api/samples/{$sample->id}/scan-flow", [
            'action_type' => 'return_room',
            'location_to' => '样品室',
        ])->assertForbidden()
            ->assertJsonPath('permission', 'sample_flows.return_room');

        $this->assertDatabaseCount('sample_flows', 0);
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

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'sample_scan_'.str()->random(8), 'guard_name' => 'web']);
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

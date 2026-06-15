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

class SampleFlowCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_flow_card_preview_returns_sample_profile_and_flows(): void
    {
        $viewer = $this->userWithPermissions(['samples.read', 'sample_flows.read']);
        $sample = $this->receivedSample(['sample_no' => 'S-FLOW-001']);
        $sample->flows()->create([
            'action_type' => 'receive',
            'action_by' => $viewer->id,
            'action_time' => Carbon::parse('2026-06-15 12:17:08'),
            'holder_from' => null,
            'holder_to' => '样品室',
            'location_from' => null,
            'location_to' => '样品室',
            'remark' => '样品接收',
        ]);

        $this->getJsonAs($viewer, "/api/samples/{$sample->id}/flow-card")
            ->assertOk()
            ->assertJsonPath('data.sample.sample_no', 'S-FLOW-001')
            ->assertJsonPath('data.flows.0.action_type', 'receive')
            ->assertJsonPath('data.flows.0.action_time', '2026-06-15 12:17:08')
            ->assertJsonPath('data.flows.0.action_by_name', $viewer->name);
    }

    public function test_flow_card_requires_flow_read_permission(): void
    {
        $viewer = $this->userWithPermissions(['samples.read']);
        $sample = $this->receivedSample(['sample_no' => 'S-FLOW-002']);

        $this->getJsonAs($viewer, "/api/samples/{$sample->id}/flow-card")
            ->assertForbidden();
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
        $role = Role::create(['name' => 'sample_flow_card_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }
}

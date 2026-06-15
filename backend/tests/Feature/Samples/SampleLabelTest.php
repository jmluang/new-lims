<?php

namespace Tests\Feature\Samples;

use App\Models\Sample;
use App\Models\TestOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SampleLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_sample_label_preview_returns_company_name_model_number_status_and_qr_labels(): void
    {
        $printer = $this->userWithPermissions(['sample_labels.print']);
        $sample = $this->sample('SAMPLE-001');

        $this->postJsonAs($printer, '/api/sample-labels/preview', [
            'sample_ids' => [$sample->id],
            'label_width_mm' => 40,
            'label_height_mm' => 60,
        ])->assertOk()
            ->assertJsonPath('data.0.client_company', '中山市样品客户')
            ->assertJsonPath('data.0.sample_name', '控制器')
            ->assertJsonPath('data.0.model', 'CTRL-1')
            ->assertJsonPath('data.0.sample_no', 'SAMPLE-001')
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('data.0.qr_text', 'SAMPLE-001')
            ->assertJsonPath('meta.label_width_mm', 40)
            ->assertJsonPath('meta.label_height_mm', 60);
    }

    private function sample(string $sampleNo): Sample
    {
        $order = TestOrder::query()->create([
            'order_no' => 'ORDER-LABEL',
            'contract_no' => 'CONTRACT-LABEL',
            'order_date' => '2026-06-12',
            'urgency' => 'normal',
            'client_company' => '中山市样品客户',
            'sample_status' => 'received',
        ]);

        return Sample::query()->create([
            'test_order_id' => $order->id,
            'sample_no' => $sampleNo,
            'sample_name' => '控制器',
            'model' => 'CTRL-1',
            'quantity' => 1,
            'status' => 'pending',
            'current_holder' => '样品室',
        ]);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_sample_label_'.str()->random(8), 'guard_name' => 'web']);
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
}

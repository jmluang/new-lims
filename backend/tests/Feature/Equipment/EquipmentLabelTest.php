<?php

namespace Tests\Feature\Equipment;

use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EquipmentLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_label_preview_returns_print_ready_label_data(): void
    {
        $printer = $this->userWithPermissions(['equipment_labels.print']);
        $equipment = Equipment::query()->create([
            'equipment_no' => 'EQ-LABEL',
            'name' => 'Label Equipment',
            'manufacturer' => 'Acme',
            'model' => 'L1',
            'status' => 'active',
        ]);

        $this->postJsonAs($printer, '/api/equipment-labels/preview', [
            'equipment_ids' => [$equipment->id],
            'label_width_mm' => 40,
            'label_height_mm' => 60,
        ])->assertOk()
            ->assertJsonPath('data.0.equipment_no', 'EQ-LABEL')
            ->assertJsonPath('data.0.name', 'Label Equipment')
            ->assertJsonPath('data.0.qr_text', 'EQ-LABEL')
            ->assertJsonPath('data.0.footer', 'XPD_LIMS')
            ->assertJsonPath('meta.label_width_mm', 40)
            ->assertJsonPath('meta.label_height_mm', 60);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_equipment_label_'.str()->random(8), 'guard_name' => 'web']);
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

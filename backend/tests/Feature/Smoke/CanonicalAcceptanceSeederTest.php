<?php

namespace Tests\Feature\Smoke;

use App\Models\User;
use Database\Seeders\CanonicalAcceptanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CanonicalAcceptanceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_acceptance_users_groups_and_permissions_are_seeded(): void
    {
        $this->seed(CanonicalAcceptanceSeeder::class);

        foreach ([
            'super_admin@example.test',
            'system_admin@example.test',
            'customer_viewer@example.test',
            'customer_editor@example.test',
            'equipment_manager@example.test',
            'test_order_manager@example.test',
            'sample_manager@example.test',
            'auditor@example.test',
            'locked_user@example.test',
        ] as $email) {
            $this->assertDatabaseHas('users', ['email' => $email]);
        }

        $this->assertTrue(User::query()->where('email', 'locked_user@example.test')->firstOrFail()->locked_at !== null);
        $this->assertTrue(Role::query()->where('name', 'system_admin')->firstOrFail()->hasPermissionTo('system.backups.restore'));
        $this->assertTrue(Role::query()->where('name', 'customer_viewer')->firstOrFail()->hasPermissionTo('customers.read'));
        $this->assertFalse(Role::query()->where('name', 'customer_viewer')->firstOrFail()->hasPermissionTo('customers.field.phone.read'));

        $equipmentManager = Role::query()->where('name', 'equipment_manager')->firstOrFail();
        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $this->assertTrue($equipmentManager->hasPermissionTo("equipment_systems.{$action}"));
            $this->assertTrue($equipmentManager->hasPermissionTo("temp_humidity_records.{$action}"));
        }

        foreach (['device_image', 'manual_files', 'instruction_files', 'calibration_files', 'other_files'] as $field) {
            $this->assertTrue($equipmentManager->hasPermissionTo("equipment.field.{$field}.read"));
            $this->assertTrue($equipmentManager->hasPermissionTo("equipment.field.{$field}.update"));
            $this->assertFalse(Permission::query()->where('name', "equipment.field.{$field}.export")->exists());
        }

        $testOrderManager = Role::query()->where('name', 'test_order_manager')->firstOrFail();
        $this->assertTrue($testOrderManager->hasPermissionTo('test_orders.create'));
        $this->assertTrue($testOrderManager->hasPermissionTo('test_order_standards.create'));
        $this->assertTrue($testOrderManager->hasPermissionTo('test_order_samples.create'));
        $this->assertTrue($testOrderManager->hasPermissionTo('samples.receive'));
        $this->assertFalse($testOrderManager->hasPermissionTo('sample_flows.create'));

        $sampleManager = Role::query()->where('name', 'sample_manager')->firstOrFail();
        $this->assertTrue($sampleManager->hasPermissionTo('samples.receive'));
        $this->assertTrue($sampleManager->hasPermissionTo('sample_flows.create'));
        $this->assertTrue($sampleManager->hasPermissionTo('equipment_locations.read'));
        $this->assertFalse($sampleManager->hasPermissionTo('test_orders.create'));
    }
}

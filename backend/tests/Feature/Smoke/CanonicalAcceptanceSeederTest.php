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
        foreach (['device_image', 'manual_files', 'instruction_files', 'calibration_files', 'other_files'] as $field) {
            $this->assertTrue($equipmentManager->hasPermissionTo("equipment.field.{$field}.read"));
            $this->assertTrue($equipmentManager->hasPermissionTo("equipment.field.{$field}.update"));
            $this->assertFalse(Permission::query()->where('name', "equipment.field.{$field}.export")->exists());
        }
    }
}

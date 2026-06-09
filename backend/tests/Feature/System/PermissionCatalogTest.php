<?php

namespace Tests\Feature\System;

use App\Models\User;
use App\Services\Authorization\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_contains_first_release_resource_and_field_permissions(): void
    {
        $permissionNames = app(PermissionCatalog::class)->permissionNames();

        $this->assertSame($permissionNames, array_values(array_unique($permissionNames)));
        $this->assertEqualsCanonicalizing($this->expectedPermissionNames(), $permissionNames);
    }

    public function test_catalog_api_returns_assignable_permission_matrix(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/system/permissions/catalog')
            ->assertOk()
            ->assertJsonPath('data.resources.customers.actions', ['read', 'create', 'update', 'delete', 'export'])
            ->assertJsonPath('data.resources.customers.fields.phone', ['read', 'update', 'export'])
            ->assertJsonPath('data.resources.standards.actions', ['read', 'create', 'update', 'delete', 'export'])
            ->assertJsonPath('data.resources.standard_catalogs.actions', ['read', 'create', 'update', 'delete'])
            ->assertJsonPath('data.resources.standard_items.actions', ['read', 'create', 'update', 'delete'])
            ->assertJsonPath('data.resources.test_orders.actions', ['read', 'create', 'update', 'delete', 'export'])
            ->assertJsonPath('data.resources.samples.actions', ['read', 'receive', 'update', 'export'])
            ->assertJsonPath('data.resources.equipment.fields.serial_no', ['read', 'update', 'export'])
            ->assertJsonMissingPath('data.resources.equipment.fields.legacy_placement');
    }

    /**
     * @return array<int, string>
     */
    private function expectedPermissionNames(): array
    {
        return [
            'system.users.read',
            'system.users.create',
            'system.users.update',
            'system.users.delete',
            'system.users.export',
            'system.users.field.phone.read',
            'system.users.field.phone.update',
            'system.users.field.email.read',
            'system.users.field.email.update',
            'system.departments.read',
            'system.departments.create',
            'system.departments.update',
            'system.departments.delete',
            'system.groups.read',
            'system.groups.create',
            'system.groups.update',
            'system.groups.delete',
            'system.audit_logs.read',
            'system.audit_logs.export',
            'system.dictionaries.read',
            'system.dictionaries.create',
            'system.dictionaries.update',
            'system.dictionaries.delete',
            'system.backups.read',
            'system.backups.create',
            'system.backups.restore',
            'customers.read',
            'customers.create',
            'customers.update',
            'customers.delete',
            'customers.export',
            'customers.field.credit_code.read',
            'customers.field.credit_code.update',
            'customers.field.credit_code.export',
            'customers.field.phone.read',
            'customers.field.phone.update',
            'customers.field.phone.export',
            'customers.field.email.read',
            'customers.field.email.update',
            'customers.field.email.export',
            'customer_contacts.read',
            'customer_contacts.create',
            'customer_contacts.update',
            'customer_contacts.delete',
            'customer_contacts.export',
            'customer_contacts.field.phone.read',
            'customer_contacts.field.phone.update',
            'customer_contacts.field.phone.export',
            'customer_contacts.field.email.read',
            'customer_contacts.field.email.update',
            'customer_contacts.field.email.export',
            'standards.read',
            'standards.create',
            'standards.update',
            'standards.delete',
            'standards.export',
            'standard_catalogs.read',
            'standard_catalogs.create',
            'standard_catalogs.update',
            'standard_catalogs.delete',
            'standard_items.read',
            'standard_items.create',
            'standard_items.update',
            'standard_items.delete',
            'test_orders.read',
            'test_orders.create',
            'test_orders.update',
            'test_orders.delete',
            'test_orders.export',
            'test_order_standards.read',
            'test_order_standards.create',
            'test_order_standards.update',
            'test_order_standards.delete',
            'test_order_samples.read',
            'test_order_samples.create',
            'test_order_samples.update',
            'test_order_samples.delete',
            'samples.read',
            'samples.receive',
            'samples.update',
            'samples.export',
            'sample_flows.read',
            'sample_flows.create',
            'equipment.read',
            'equipment.create',
            'equipment.update',
            'equipment.delete',
            'equipment.export',
            'equipment.field.serial_no.read',
            'equipment.field.serial_no.update',
            'equipment.field.serial_no.export',
            'equipment.field.device_image.read',
            'equipment.field.device_image.update',
            'equipment.field.manual_files.read',
            'equipment.field.manual_files.update',
            'equipment.field.instruction_files.read',
            'equipment.field.instruction_files.update',
            'equipment.field.calibration_files.read',
            'equipment.field.calibration_files.update',
            'equipment.field.other_files.read',
            'equipment.field.other_files.update',
            'equipment_locations.read',
            'equipment_locations.create',
            'equipment_locations.update',
            'equipment_locations.delete',
            'equipment_labels.read',
            'equipment_labels.print',
            'temp_humidity_records.read',
            'temp_humidity_records.create',
            'temp_humidity_records.update',
            'temp_humidity_records.delete',
        ];
    }
}

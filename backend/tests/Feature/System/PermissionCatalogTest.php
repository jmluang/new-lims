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

        $response = $this->getJson('/api/system/permissions/catalog');
        $response
            ->assertOk()
            ->assertJsonPath('data.resources.customers.actions', ['read', 'create', 'update', 'delete', 'export'])
            ->assertJsonPath('data.resources.customers.fields.phone', ['read', 'update', 'export'])
            ->assertJsonPath('data.resources.standards.actions', ['read', 'create', 'update', 'delete', 'export'])
            ->assertJsonPath('data.resources.standard_catalogs.actions', ['read', 'create', 'update', 'delete'])
            ->assertJsonPath('data.resources.standard_items.actions', ['read', 'create', 'update', 'delete'])
            ->assertJsonPath('data.resources.test_orders.actions', ['read', 'create', 'update', 'delete', 'export', 'notify', 'print'])
            ->assertJsonPath('data.resources.samples.actions', ['read', 'receive', 'update', 'export'])
            ->assertJsonPath('data.resources.sample_flows.actions', ['read', 'create', 'return_room'])
            ->assertJsonPath('data.resources.equipment.fields.serial_no', ['read', 'update', 'export'])
            ->assertJsonPath('data.resources.equipment_systems.actions', ['read', 'create', 'update', 'delete'])
            ->assertJsonMissingPath('data.resources.equipment.fields.legacy_placement')
            ->assertJsonMissingPath('data.resources.system.dictionaries');
        $resources = $response->json('data.resources');
        $this->assertSame(['read', 'create', 'cancel'], $resources['pdf.workflow']['actions']);
        $this->assertSame(['read', 'sign_assigned', 'reject'], $resources['pdf.request']['actions']);
        $this->assertSame(['resolve'], $resources['pdf.manual_review']['actions']);
        $this->assertSame(['manage'], $resources['pdf.evidence_hold']['actions']);
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
            'test_orders.notify',
            'test_orders.print',
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
            'sample_labels.read',
            'sample_labels.print',
            'sample_flows.read',
            'sample_flows.create',
            'sample_flows.return_room',
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
            'equipment_systems.read',
            'equipment_systems.create',
            'equipment_systems.update',
            'equipment_systems.delete',
            'equipment_labels.read',
            'equipment_labels.print',
            'equipment_usage_records.read',
            'equipment_usage_records.create',
            'equipment_usage_records.update',
            'equipment_usage_records.delete',
            'temp_humidity_records.read',
            'temp_humidity_records.create',
            'temp_humidity_records.update',
            'temp_humidity_records.delete',
            'calibration_projects.read',
            'calibration_projects.create',
            'calibration_projects.update',
            'calibration_projects.delete',
            'calibration_project_labels.print',
            'equipment_calibrations.read',
            'equipment_calibrations.create',
            'equipment_calibrations.update',
            'equipment_calibrations.delete',
            'equipment_calibrations.field.attachment_files.read',
            'equipment_calibrations.field.attachment_files.update',
            'equipment_calibrations.field.photo_files.read',
            'equipment_calibrations.field.photo_files.update',
            'pdf_signing.read',
            'pdf_signing.create',
            'pdf_verification.read',
            'pdf_verification.create',
            'pdf_files.read',
            'pdf_files.download',
            'pdf_verification_logs.read',
            'pdf_verification_logs.download',
            'pdf_digital_signatures.read',
            'pdf_digital_signatures.create',
            'pdf_digital_signatures.update',
            'pdf_digital_signatures.delete',
            'pdf_perforation_stamps.read',
            'pdf_perforation_stamps.create',
            'pdf_perforation_stamps.update',
            'pdf_perforation_stamps.delete',
            'pdf_function_stamps.read',
            'pdf_function_stamps.create',
            'pdf_function_stamps.update',
            'pdf_function_stamps.delete',
            'pdf_certificate_templates.read',
            'pdf_certificate_templates.create',
            'pdf_certificate_templates.update',
            'pdf_certificate_templates.delete',
            'pdf.document.read',
            'pdf.document.update',
            'pdf.document.delete',
            'pdf.workflow.read',
            'pdf.workflow.create',
            'pdf.workflow.cancel',
            'pdf.request.read',
            'pdf.request.sign_assigned',
            'pdf.request.reject',
            'pdf.organization_key.use',
            'pdf.revision.download',
            'pdf.manual_review.resolve',
            'pdf.evidence_hold.manage',
        ];
    }
}

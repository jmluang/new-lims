<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_can_create_update_and_delete_customer_with_audit_logs(): void
    {
        $admin = $this->userWithPermissions([
            'customers.read',
            'customers.create',
            'customers.update',
            'customers.delete',
            'customers.field.credit_code.read',
            'customers.field.credit_code.update',
            'customers.field.phone.read',
            'customers.field.phone.update',
            'customers.field.email.read',
            'customers.field.email.update',
        ]);

        $customerId = $this->postJsonAs($admin, '/api/customers', [
            'name' => 'Acme Lab',
            'credit_code' => '91330000123456789X',
            'type' => 'enterprise',
            'level' => 'a',
            'source' => 'referral',
            'industry' => 'testing',
            'phone' => '13800000000',
            'email' => 'acme@example.test',
            'address' => 'Hangzhou',
            'remark' => 'VIP',
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonMissingPath('data.type')
            ->assertJsonMissingPath('data.level')
            ->assertJsonMissingPath('data.source')
            ->assertJsonMissingPath('data.industry')
            ->json('data.id');

        $this->putJsonAs($admin, "/api/customers/{$customerId}", [
            'name' => 'Acme Lab Updated',
            'credit_code' => '91330000123456789X',
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Acme Lab Updated');

        $this->deleteJsonAs($admin, "/api/customers/{$customerId}")->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'status' => 'disabled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.create', 'subject_id' => (string) $customerId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.update', 'subject_id' => (string) $customerId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customers.delete', 'subject_id' => (string) $customerId]);
    }

    public function test_customer_contacts_keep_exactly_one_default_contact(): void
    {
        $admin = $this->userWithPermissions([
            'customers.read',
            'customers.create',
            'customer_contacts.create',
            'customer_contacts.update',
            'customer_contacts.field.phone.read',
            'customer_contacts.field.phone.update',
            'customer_contacts.field.email.read',
            'customer_contacts.field.email.update',
        ]);
        $customer = Customer::query()->create(['name' => 'Contact Customer', 'status' => 'active']);

        $firstContactId = $this->postJsonAs($admin, "/api/customers/{$customer->id}/contacts", [
            'name' => 'First Contact',
            'phone' => '13800000001',
            'email' => 'first@example.test',
            'is_default' => true,
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $secondContactId = $this->postJsonAs($admin, "/api/customers/{$customer->id}/contacts", [
            'name' => 'Second Contact',
            'phone' => '13800000002',
            'email' => 'second@example.test',
            'is_default' => true,
            'status' => 'active',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('customer_contacts', ['id' => $firstContactId, 'is_default' => false]);
        $this->assertDatabaseHas('customer_contacts', ['id' => $secondContactId, 'is_default' => true]);

        $this->putJsonAs($admin, "/api/customers/{$customer->id}/contacts/{$firstContactId}", [
            'name' => 'First Contact',
            'is_default' => true,
            'status' => 'active',
        ])->assertOk();

        $this->assertDatabaseHas('customer_contacts', ['id' => $firstContactId, 'is_default' => true]);
        $this->assertDatabaseHas('customer_contacts', ['id' => $secondContactId, 'is_default' => false]);
    }

    public function test_customer_list_filters_and_includes_default_contact(): void
    {
        $admin = $this->userWithPermissions([
            'customers.read',
            'customers.field.credit_code.read',
            'customers.field.phone.read',
            'customers.field.email.read',
            'customer_contacts.field.phone.read',
            'customer_contacts.field.email.read',
        ]);
        $target = Customer::query()->create([
            'name' => 'Filtered Customer',
            'credit_code' => 'FILTER-CREDIT',
            'phone' => '13800001111',
            'email' => 'filtered@example.test',
            'status' => 'active',
        ]);
        $target->contacts()->create([
            'name' => 'Default Contact',
            'phone' => '13800002222',
            'email' => 'contact@example.test',
            'is_default' => true,
            'status' => 'active',
        ]);
        Customer::query()->create([
            'name' => 'Other Customer',
            'phone' => '13900001111',
            'status' => 'disabled',
        ]);

        $this->getJsonAs($admin, '/api/customers?search=FILTER-CREDIT&status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.default_contact.name', 'Default Contact')
            ->assertJsonPath('data.0.default_contact.phone', '13800002222')
            ->assertJsonMissingPath('data.0.type')
            ->assertJsonMissingPath('data.0.level')
            ->assertJsonMissingPath('data.0.source')
            ->assertJsonMissingPath('data.0.industry');
    }

    public function test_customer_export_uses_filters_and_exportable_sensitive_fields(): void
    {
        $admin = $this->userWithPermissions([
            'customers.export',
            'customers.field.credit_code.export',
            'customers.field.phone.export',
        ]);
        Customer::query()->create([
            'name' => 'Exported Customer',
            'credit_code' => 'EXPORT-CREDIT',
            'phone' => '13800003333',
            'email' => 'exported@example.test',
            'status' => 'active',
        ]);
        Customer::query()->create([
            'name' => 'Hidden Export Customer',
            'credit_code' => 'HIDDEN-CREDIT',
            'phone' => '13900003333',
            'status' => 'active',
        ]);

        $response = $this->getJsonAs($admin, '/api/customers/export?search=EXPORT-CREDIT')
            ->assertOk()
            ->assertJsonPath('headers.0', 'name');

        $this->assertStringContainsString('EXPORT-CREDIT', $response->getContent());
        $this->assertStringContainsString('13800003333', $response->getContent());
        $this->assertStringNotContainsString('exported@example.test', $response->getContent());
        $this->assertStringNotContainsString('HIDDEN-CREDIT', $response->getContent());
        $this->assertStringNotContainsString('type', $response->getContent());
        $this->assertStringNotContainsString('level', $response->getContent());
        $this->assertStringNotContainsString('source', $response->getContent());
        $this->assertStringNotContainsString('industry', $response->getContent());
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_customer_api_'.str()->random(8), 'guard_name' => 'web']);
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

    private function putJsonAs(User $user, string $uri, array $data = [])
    {
        Sanctum::actingAs($user);

        return $this->putJson($uri, $data);
    }

    private function deleteJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->deleteJson($uri);
    }

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }
}

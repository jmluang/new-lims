<?php

namespace Tests\Feature\Customers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerFieldPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_customer_list_hides_sensitive_fields_without_field_read_permissions(): void
    {
        $viewer = $this->userWithPermissions(['customers.read']);
        Customer::query()->create([
            'name' => 'Sensitive Customer',
            'credit_code' => '91330000123456789X',
            'phone' => '13800000000',
            'email' => 'secret@example.test',
            'status' => 'active',
        ]);

        $response = $this->getJsonAs($viewer, '/api/customers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sensitive Customer')
            ->assertJsonPath('data.0.credit_code', null)
            ->assertJsonPath('data.0.phone', null)
            ->assertJsonPath('data.0.email', null)
            ->assertJsonPath('meta.fields.credit_code.read', false)
            ->assertJsonPath('meta.fields.phone.read', false)
            ->assertJsonPath('meta.fields.email.read', false);

        $this->assertStringNotContainsString('91330000123456789X', $response->getContent());
        $this->assertStringNotContainsString('13800000000', $response->getContent());
        $this->assertStringNotContainsString('secret@example.test', $response->getContent());
    }

    public function test_forbidden_sensitive_field_update_is_rejected_and_audited_without_storing_new_value(): void
    {
        $editor = $this->userWithPermissions(['customers.read', 'customers.update']);
        $customer = Customer::query()->create([
            'name' => 'Original Customer',
            'credit_code' => '91330000123456789X',
            'status' => 'active',
        ]);

        $this->putJsonAs($editor, "/api/customers/{$customer->id}", [
            'name' => 'Updated Customer',
            'credit_code' => '91330000999999999X',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['credit_code']);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Original Customer',
            'credit_code' => '91330000123456789X',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'authorization.denied',
            'module' => 'customers',
            'subject_id' => (string) $customer->id,
        ]);
        $this->assertStringNotContainsString(
            '91330000999999999X',
            json_encode(AuditLog::query()->pluck('after_values')->filter()->values()->all())
        );
    }

    public function test_customer_export_excludes_fields_without_export_permissions_and_records_audit_log(): void
    {
        $viewer = $this->userWithPermissions(['customers.read', 'customers.export']);
        Customer::query()->create([
            'name' => 'Export Customer',
            'credit_code' => '91330000123456789X',
            'phone' => '13800000000',
            'email' => 'secret@example.test',
            'status' => 'active',
        ]);

        $response = $this->getJsonAs($viewer, '/api/customers/export')
            ->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString('name', $content);
        $this->assertStringContainsString('Export Customer', $content);
        $this->assertStringNotContainsString('credit_code', $content);
        $this->assertStringNotContainsString('91330000123456789X', $content);
        $this->assertStringNotContainsString('phone', $content);
        $this->assertStringNotContainsString('13800000000', $content);
        $this->assertStringNotContainsString('email', $content);
        $this->assertStringNotContainsString('secret@example.test', $content);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customers.export',
            'module' => 'customers',
        ]);
    }

    public function test_customer_viewer_cannot_mutate_customers_and_denial_is_audited(): void
    {
        $viewer = $this->userWithPermissions(['customers.read']);
        $customer = Customer::query()->create(['name' => 'Protected Customer', 'status' => 'active']);

        $this->postJsonAs($viewer, '/api/customers', ['name' => 'Denied Customer'])
            ->assertForbidden();
        $this->putJsonAs($viewer, "/api/customers/{$customer->id}", ['name' => 'Denied Update'])
            ->assertForbidden();
        $this->deleteJsonAs($viewer, "/api/customers/{$customer->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Protected Customer']);
        $this->assertSame(3, AuditLog::query()->where('action', 'authorization.denied')->count());
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_customer_permission_'.str()->random(8), 'guard_name' => 'web']);
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
}

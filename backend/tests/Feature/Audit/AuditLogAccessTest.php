<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuditLogAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_auditor_can_filter_and_view_audit_logs(): void
    {
        $auditor = $this->userWithPermissions(['system.audit_logs.read']);
        $operator = User::factory()->create(['name' => 'Operator']);
        app(AuditLogger::class)->record(
            actor: $operator,
            action: 'customers.update',
            module: 'customers',
            subject: $operator,
            before: ['name' => 'Old'],
            after: ['name' => 'New'],
            requestMeta: ['request_id' => 'audit-filter-test'],
        );

        Sanctum::actingAs($auditor);

        $this->getJson('/api/audit-logs?module=customers&action=customers.update&request_id=audit-filter-test')
            ->assertOk()
            ->assertJsonPath('data.0.actor_name_snapshot', 'Operator')
            ->assertJsonPath('data.0.module', 'customers')
            ->assertJsonPath('data.0.action', 'customers.update')
            ->assertJsonPath('data.0.before_values.name', 'Old')
            ->assertJsonPath('data.0.after_values.name', 'New');
    }

    public function test_audit_export_requires_export_permission_and_returns_filtered_logs(): void
    {
        $reader = $this->userWithPermissions(['system.audit_logs.read']);
        $exporter = $this->userWithPermissions(['system.audit_logs.read', 'system.audit_logs.export']);
        app(AuditLogger::class)->record(
            actor: $exporter,
            action: 'system.groups.permissions.update',
            module: 'system.groups',
            requestMeta: ['request_id' => 'audit-export-test'],
        );

        Sanctum::actingAs($reader);
        $this->getJson('/api/audit-logs/export?module=system.groups')->assertForbidden();

        Sanctum::actingAs($exporter);
        $response = $this->getJson('/api/audit-logs/export?module=system.groups')->assertOk();

        $this->assertStringContainsString('system.groups.permissions.update', $response->getContent());
        $this->assertStringContainsString('audit-export-test', $response->getContent());
    }

    public function test_non_auditor_cannot_read_audit_logs(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_audit_reader_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

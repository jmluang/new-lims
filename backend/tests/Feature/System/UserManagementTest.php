<?php

namespace Tests\Feature\System;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_can_create_update_lock_unlock_and_reset_user_with_audit_logs(): void
    {
        $admin = $this->userWithPermissions([
            'system.users.read',
            'system.users.create',
            'system.users.update',
            'system.users.field.phone.read',
            'system.users.field.phone.update',
            'system.departments.create',
        ]);
        $group = Role::create(['name' => 'customer_viewer', 'guard_name' => 'web', 'status' => 'active']);
        $departmentId = $this->postJsonAs($admin, '/api/system/departments', [
            'name' => 'Lab',
            'code' => 'LAB',
            'status' => 'active',
        ])->json('data.id');

        $createResponse = $this->postJsonAs($admin, '/api/system/users', [
            'name' => 'New Operator',
            'email' => 'operator@example.test',
            'password' => 'Password123!',
            'phone' => '13800000000',
            'department_id' => $departmentId,
            'status' => 'active',
            'must_change_password' => true,
            'group_ids' => [$group->id],
        ])->assertCreated();

        $userId = $createResponse->json('data.id');
        $createdUser = User::query()->findOrFail($userId);

        $this->assertSame('13800000000', $createdUser->phone);
        $this->assertSame($departmentId, $createdUser->department_id);
        $this->assertTrue($createdUser->must_change_password);
        $this->assertTrue($createdUser->hasRole($group));

        $this->putJsonAs($admin, "/api/system/users/{$userId}", [
            'name' => 'Updated Operator',
            'phone' => '13900000000',
            'department_id' => $departmentId,
            'status' => 'active',
            'must_change_password' => false,
            'group_ids' => [],
        ])->assertOk();

        $this->postJsonAs($admin, "/api/system/users/{$userId}/lock", [
            'reason' => 'Policy',
        ])->assertOk();
        $this->assertNotNull($createdUser->fresh()->locked_at);

        $this->postJsonAs($admin, "/api/system/users/{$userId}/unlock")->assertOk();
        $this->assertNull($createdUser->fresh()->locked_at);

        $createdUser->createToken('active-token');
        $this->assertSame(1, $createdUser->tokens()->count());

        $this->postJsonAs($admin, "/api/system/users/{$userId}/reset-password", [
            'password' => 'NewPassword123!',
            'must_change_password' => true,
        ])->assertOk()
            ->assertJsonPath('meta.temporary_password', 'NewPassword123!');
        $this->assertTrue($createdUser->fresh()->must_change_password);
        $this->assertSame(0, $createdUser->tokens()->count());

        $this->assertDatabaseHas('audit_logs', ['action' => 'system.users.create', 'subject_id' => (string) $userId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.users.update', 'subject_id' => (string) $userId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.users.lock', 'subject_id' => (string) $userId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.users.unlock', 'subject_id' => (string) $userId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.users.reset_password', 'subject_id' => (string) $userId]);
    }

    public function test_user_list_hides_phone_without_field_read_permission(): void
    {
        $admin = $this->userWithPermissions(['system.users.read']);
        User::factory()->create([
            'name' => 'Hidden Phone User',
            'email' => 'hidden-phone@example.test',
            'phone' => '13811112222',
        ]);

        $response = $this->getJsonAs($admin, '/api/system/users')
            ->assertOk()
            ->assertJsonPath('meta.fields.phone.read', false);

        $this->assertStringNotContainsString('13811112222', $response->getContent());
    }

    public function test_reset_password_unlocks_user_so_temporary_password_can_login(): void
    {
        $admin = $this->userWithPermissions(['system.users.update']);
        $user = User::factory()->create([
            'name' => 'Locked Operator',
            'email' => 'locked-reset@example.test',
            'password' => 'OldPassword123!',
            'status' => 'locked',
            'locked_at' => now(),
            'lock_reason' => 'failed_login_attempts',
            'failed_login_attempts' => 5,
        ]);

        $this->postJsonAs($admin, "/api/system/users/{$user->id}/reset-password", [
            'password' => 'ChangeMe123!',
            'must_change_password' => true,
        ])->assertOk()
            ->assertJsonPath('meta.temporary_password', 'ChangeMe123!');

        $this->postJson('/api/login', [
            'email' => 'locked-reset@example.test',
            'password' => 'ChangeMe123!',
        ])->assertOk()
            ->assertJsonPath('data.user.must_change_password', true);

        $user = $user->fresh();
        $this->assertSame('active', $user->status);
        $this->assertNull($user->locked_at);
        $this->assertSame(0, $user->failed_login_attempts);
    }

    public function test_reset_password_does_not_approve_pending_registration(): void
    {
        $admin = $this->userWithPermissions(['system.users.update']);
        $user = User::factory()->create([
            'name' => 'Pending Operator',
            'email' => 'pending-reset@example.test',
            'password' => 'OldPassword123!',
            'status' => 'locked',
            'locked_at' => now(),
            'lock_reason' => 'pending_approval',
        ]);

        $this->postJsonAs($admin, "/api/system/users/{$user->id}/reset-password", [
            'password' => 'ChangeMe123!',
            'must_change_password' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', 'locked')
            ->assertJsonPath('data.lock_reason', 'pending_approval');

        $this->postJson('/api/login', [
            'email' => 'pending-reset@example.test',
            'password' => 'ChangeMe123!',
        ])->assertForbidden();

        $user = $user->fresh();
        $this->assertSame('locked', $user->status);
        $this->assertNotNull($user->locked_at);
        $this->assertSame('pending_approval', $user->lock_reason);
    }

    public function test_user_list_can_filter_by_search_status_department_and_group(): void
    {
        $admin = $this->userWithPermissions(['system.users.read']);
        $department = Department::query()->create(['name' => 'QA', 'code' => 'QA', 'status' => 'active']);
        $targetGroup = Role::create(['name' => 'qa_operator', 'guard_name' => 'web', 'status' => 'active']);
        $otherGroup = Role::create(['name' => 'other_operator', 'guard_name' => 'web', 'status' => 'active']);
        $target = User::factory()->create([
            'name' => 'Filtered Operator',
            'email' => 'filtered@example.test',
            'department_id' => $department->id,
            'status' => 'locked',
        ]);
        $target->assignRole($targetGroup);
        User::factory()->create([
            'name' => 'Unrelated Operator',
            'email' => 'unrelated@example.test',
            'department_id' => null,
            'status' => 'active',
        ])->assignRole($otherGroup);

        $this->getJsonAs($admin, "/api/system/users?search=Filtered&status=locked&department_id={$department->id}&group_id={$targetGroup->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('meta.total', 1);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_admin_'.str()->random(8), 'guard_name' => 'web']);
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

    private function getJsonAs(User $user, string $uri)
    {
        Sanctum::actingAs($user);

        return $this->getJson($uri);
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_registration_creates_a_locked_account_pending_admin_approval(): void
    {
        $department = Department::query()->create([
            'name' => 'Lab',
            'code' => 'LAB',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/register', [
            'name' => 'New Operator',
            'email' => 'new-operator@example.test',
            'password' => 'Password123!',
            'phone' => '13800000000',
            'department_id' => $department->id,
            'status' => 'active',
            'must_change_password' => true,
            'group_ids' => [1],
        ])->assertCreated();

        $user = User::query()->where('email', 'new-operator@example.test')->firstOrFail();

        $response->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'new-operator@example.test')
            ->assertJsonMissingPath('data.groups');

        $this->assertSame('New Operator', $user->name);
        $this->assertSame('13800000000', $user->phone);
        $this->assertSame($department->id, $user->department_id);
        $this->assertSame('locked', $user->status);
        $this->assertNotNull($user->locked_at);
        $this->assertSame('pending_approval', $user->lock_reason);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertCount(0, $user->roles);

        // A freshly registered account cannot log in until an admin unlocks it.
        $this->postJson('/api/login', [
            'email' => 'new-operator@example.test',
            'password' => 'Password123!',
        ])->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.register',
            'subject_id' => (string) $user->id,
        ]);
    }

    public function test_register_options_expose_only_active_department_tree(): void
    {
        $active = Department::query()->create([
            'name' => 'Active Lab',
            'code' => 'ACTIVE',
            'status' => 'active',
        ]);
        Department::query()->create([
            'name' => 'Disabled Lab',
            'code' => 'DISABLED',
            'status' => 'disabled',
        ]);
        Department::query()->create([
            'parent_id' => $active->id,
            'name' => 'Child Lab',
            'code' => 'CHILD',
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/register/options')
            ->assertOk()
            ->assertJsonPath('data.departments.0.name', 'Active Lab')
            ->assertJsonPath('data.departments.0.children.0.name', 'Child Lab');

        $this->assertStringNotContainsString('Disabled Lab', $response->getContent());
    }
}

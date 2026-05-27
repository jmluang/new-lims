<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoginEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_current_user_and_plain_text_token(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.test',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'email' => 'operator@example.test',
            'password' => 'Password123!',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_locked_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'locked@example.test',
            'password' => Hash::make('Password123!'),
            'status' => 'locked',
            'locked_at' => now(),
        ]);

        $this->postJson('/api/login', [
            'email' => 'locked@example.test',
            'password' => 'Password123!',
        ])->assertForbidden();
    }

    public function test_failed_login_attempts_increment_and_lock_user(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.test',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', [
                'email' => 'operator@example.test',
                'password' => 'WrongPassword',
            ])->assertUnauthorized();
        }

        $this->assertSame(5, $user->fresh()->failed_login_attempts);
        $this->assertSame('locked', $user->fresh()->status);
        $this->assertNotNull($user->fresh()->locked_at);
    }

    public function test_user_must_change_password_before_accessing_business_apis(): void
    {
        $user = User::factory()->create([
            'email' => 'first-login@example.test',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
            'must_change_password' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/customers')
            ->assertStatus(409)
            ->assertJsonPath('error', 'password_change_required');

        $this->postJson('/api/auth/password', [
            'current_password' => 'Password123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
    }
}

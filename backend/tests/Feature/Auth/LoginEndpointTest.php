<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}

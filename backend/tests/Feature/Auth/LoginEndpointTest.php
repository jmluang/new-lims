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

    public function test_repeated_failed_logins_are_throttled_without_locking_the_account(): void
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

        // The 6th attempt from the same email + IP is throttled (429)...
        $this->postJson('/api/login', [
            'email' => 'operator@example.test',
            'password' => 'WrongPassword',
        ])->assertStatus(429);

        // ...but the account is never auto-locked, so an attacker cannot lock a
        // legitimate user out by deliberately failing logins.
        $user = $user->fresh();
        $this->assertSame(5, $user->failed_login_attempts);
        $this->assertSame('active', $user->status);
        $this->assertNull($user->locked_at);
    }

    public function test_successful_login_clears_the_failed_attempt_throttle(): void
    {
        $user = User::factory()->create([
            'email' => 'operator2@example.test',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
        ]);

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/login', [
                'email' => 'operator2@example.test',
                'password' => 'WrongPassword',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/login', [
            'email' => 'operator2@example.test',
            'password' => 'Password123!',
        ])->assertOk();

        // Counter reset and throttle cleared after a good login.
        $this->assertSame(0, $user->fresh()->failed_login_attempts);

        $this->postJson('/api/login', [
            'email' => 'operator2@example.test',
            'password' => 'WrongPassword',
        ])->assertUnauthorized();
    }

    public function test_many_successful_logins_from_one_ip_are_never_throttled(): void
    {
        // Simulates many colleagues behind a single office/NAT IP all signing in
        // correctly within a minute. None must be blocked (regression for the
        // blanket per-IP middleware limiter that counted successful logins).
        foreach (range(1, 20) as $i) {
            User::factory()->create([
                'email' => "colleague{$i}@example.test",
                'password' => Hash::make('Password123!'),
                'status' => 'active',
            ]);

            $this->postJson('/api/login', [
                'email' => "colleague{$i}@example.test",
                'password' => 'Password123!',
            ])->assertOk();
        }
    }

    public function test_password_spraying_across_accounts_from_one_ip_is_throttled(): void
    {
        foreach (range(1, self::ipSprayThreshold()) as $i) {
            User::factory()->create([
                'email' => "spray{$i}@example.test",
                'password' => Hash::make('Password123!'),
                'status' => 'active',
            ]);

            $this->postJson('/api/login', [
                'email' => "spray{$i}@example.test",
                'password' => 'CommonPassword1',
            ])->assertUnauthorized();
        }

        // One more distinct account from the same IP is now blocked, even though
        // no single account was hit more than once.
        User::factory()->create([
            'email' => 'spray-final@example.test',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'email' => 'spray-final@example.test',
            'password' => 'CommonPassword1',
        ])->assertStatus(429);
    }

    public function test_bruteforce_on_one_account_does_not_throttle_another_user_on_same_ip(): void
    {
        User::factory()->create([
            'email' => 'victim@example.test',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
        ]);
        User::factory()->create([
            'email' => 'coworker@example.test',
            'password' => Hash::make('Password123!'),
            'status' => 'active',
        ]);

        // Attacker exhausts the victim's per-account throttle from this IP.
        foreach (range(1, self::MAX_FAILED_ATTEMPTS_FOR_TEST) as $i) {
            $this->postJson('/api/login', [
                'email' => 'victim@example.test',
                'password' => 'WrongPassword',
            ])->assertUnauthorized();
        }
        $this->postJson('/api/login', [
            'email' => 'victim@example.test',
            'password' => 'WrongPassword',
        ])->assertStatus(429);

        // The coworker on the same IP is unaffected and logs in normally.
        $this->postJson('/api/login', [
            'email' => 'coworker@example.test',
            'password' => 'Password123!',
        ])->assertOk();
    }

    private const MAX_FAILED_ATTEMPTS_FOR_TEST = 5;

    private static function ipSprayThreshold(): int
    {
        return 30;
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

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;

    public function login(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            if ($user) {
                $this->recordFailedAttempt($user, $auditLogger);
            }

            throw ValidationException::withMessages([
                'email' => ['auth_failed'],
            ])->status(401);
        }

        if ($user->status === 'locked' || $user->locked_at !== null) {
            $auditLogger->record(
                actor: $user,
                action: 'auth.login.locked_denied',
                module: 'auth',
                subject: $user,
                after: ['email' => $user->email],
            );

            abort(403);
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'last_login_at' => Carbon::now(),
        ])->save();

        $token = $user->createToken('spa')->plainTextToken;

        $auditLogger->record(
            actor: $user,
            action: 'auth.login',
            module: 'auth',
            subject: $user,
            after: ['email' => $user->email],
        );

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'must_change_password' => $user->must_change_password,
                ],
            ],
        ]);
    }

    public function logout(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();
        $user?->currentAccessToken()?->delete();

        if ($user) {
            $auditLogger->record(
                actor: $user,
                action: 'auth.logout',
                module: 'auth',
                subject: $user,
            );
        }

        return response()->json(['data' => ['logged_out' => true]]);
    }

    public function changePassword(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->forceFill([
            'password' => $data['password'],
            'password_changed_at' => Carbon::now(),
            'must_change_password' => false,
        ])->save();

        $auditLogger->record(
            actor: $user,
            action: 'auth.password_changed',
            module: 'auth',
            subject: $user,
            after: ['email' => $user->email, 'must_change_password' => false],
        );

        return response()->json([
            'data' => [
                'must_change_password' => false,
            ],
        ]);
    }

    private function recordFailedAttempt(User $user, AuditLogger $auditLogger): void
    {
        $user->forceFill([
            'failed_login_attempts' => $user->failed_login_attempts + 1,
        ]);

        if ($user->failed_login_attempts >= self::MAX_FAILED_ATTEMPTS) {
            $user->forceFill([
                'status' => 'locked',
                'locked_at' => Carbon::now(),
                'lock_reason' => 'failed_login_attempts',
            ]);
        }

        $user->save();

        $auditLogger->record(
            actor: $user,
            action: 'auth.login_failed',
            module: 'auth',
            subject: $user,
            after: [
                'email' => $user->email,
                'failed_login_attempts' => $user->failed_login_attempts,
                'status' => $user->status,
            ],
        );
    }
}

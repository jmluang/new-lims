<?php

namespace App\Actions\System;

use App\Models\User;
use Illuminate\Support\Carbon;

class ResetUserPasswordAction
{
    public function execute(User $user, string $password, bool $mustChangePassword = true): User
    {
        $unlocksFailedLoginLock = $user->status === 'locked'
            && $user->lock_reason === 'failed_login_attempts';

        $user->forceFill([
            'password' => $password,
            'password_changed_at' => Carbon::now(),
            'must_change_password' => $mustChangePassword,
            'status' => $unlocksFailedLoginLock ? 'active' : $user->status,
            'locked_at' => $unlocksFailedLoginLock ? null : $user->locked_at,
            'lock_reason' => $unlocksFailedLoginLock ? null : $user->lock_reason,
            'failed_login_attempts' => 0,
        ])->save();

        $user->tokens()->delete();

        return $user->fresh();
    }
}

<?php

namespace App\Actions\System;

use App\Models\User;
use Illuminate\Support\Carbon;

class ResetUserPasswordAction
{
    public function execute(User $user, string $password, bool $mustChangePassword = true): User
    {
        $user->forceFill([
            'password' => $password,
            'password_changed_at' => Carbon::now(),
            'must_change_password' => $mustChangePassword,
            'status' => $user->status === 'locked' ? 'active' : $user->status,
            'locked_at' => null,
            'lock_reason' => null,
            'failed_login_attempts' => 0,
        ])->save();

        $user->tokens()->delete();

        return $user->fresh();
    }
}

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
        ])->save();

        $user->tokens()->delete();

        return $user->fresh();
    }
}

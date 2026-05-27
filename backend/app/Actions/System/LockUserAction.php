<?php

namespace App\Actions\System;

use App\Models\User;
use Illuminate\Support\Carbon;

class LockUserAction
{
    public function execute(User $user, ?string $reason = null): User
    {
        $user->forceFill([
            'status' => 'locked',
            'locked_at' => Carbon::now(),
            'lock_reason' => $reason,
        ])->save();

        return $user->fresh();
    }
}

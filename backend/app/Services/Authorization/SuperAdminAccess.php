<?php

namespace App\Services\Authorization;

use App\Models\User;

class SuperAdminAccess
{
    public const SYSTEM_KEY = 'super_admin';

    public function userHasAccess(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->roles()
            ->where('system_key', self::SYSTEM_KEY)
            ->where('status', 'active')
            ->exists();
    }
}

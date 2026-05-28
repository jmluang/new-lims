<?php

namespace App\Services\Authorization;

use App\Models\User;

class PermissionAccess
{
    public function __construct(private readonly SuperAdminAccess $superAdminAccess) {}

    public function userCan(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->superAdminAccess->userHasAccess($user)) {
            return true;
        }

        if ($user->getDirectPermissions()->contains('name', $permission)) {
            return true;
        }

        return $user->roles()
            ->where('status', 'active')
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->exists();
    }
}

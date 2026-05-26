<?php

namespace App\Services\Authorization;

use App\Models\User;
use Illuminate\Support\Collection;

class EffectivePermissionService
{
    public function __construct(private readonly PermissionCatalog $catalog) {}

    public function forUser(User $user): EffectivePermissions
    {
        $grantedPermissions = $this->grantedPermissions($user);
        $resources = [];

        foreach ($this->catalog->resourceActions() as $resource => $actions) {
            foreach ($actions as $action) {
                $resources[$resource]['actions'][$action] = $grantedPermissions->contains("{$resource}.{$action}");
            }

            $resources[$resource]['fields'] ??= [];
        }

        foreach ($this->catalog->fieldActions() as $resource => $fields) {
            $resources[$resource] ??= [
                'actions' => [],
                'fields' => [],
            ];

            foreach ($fields as $field => $actions) {
                foreach ($actions as $action) {
                    $resources[$resource]['fields'][$field][$action] = $grantedPermissions->contains(
                        "{$resource}.field.{$field}.{$action}"
                    );
                }
            }
        }

        return new EffectivePermissions($resources);
    }

    /**
     * @return Collection<int, string>
     */
    private function grantedPermissions(User $user)
    {
        if ($user->hasRole('super_admin')) {
            return collect($this->catalog->permissionNames());
        }

        return $user->getAllPermissions()
            ->pluck('name')
            ->unique()
            ->values();
    }
}

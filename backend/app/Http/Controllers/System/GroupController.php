<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class GroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'system.groups.read');

        return response()->json([
            'data' => Role::query()->with('permissions')->orderBy('id')->get()->map(fn (Role $role): array => $this->serializeRole($role)),
        ]);
    }

    public function show(Request $request, Role $group): JsonResponse
    {
        $this->authorizePermission($request, 'system.groups.read');

        return response()->json(['data' => $this->serializeRole($group->load('permissions'))]);
    }

    public function store(Request $request, AuditLogger $auditLogger, PermissionCatalog $permissionCatalog): JsonResponse
    {
        $this->authorizePermission($request, 'system.groups.create');

        $data = $request->validate($this->rules());
        $permissions = $data['permissions'] ?? [];
        $displayName = $data['name'];
        unset($data['permissions']);
        $role = Role::create([
            'name' => $this->uniqueRoleName($displayName),
            'display_name' => $displayName,
            'guard_name' => 'web',
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'is_system' => $data['is_system'] ?? false,
        ]);
        $this->syncPermissions($role, $permissions, $permissionCatalog);

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.groups.create',
            module: 'system.groups',
            subject: $role,
            after: $this->auditValues($role->load('permissions')),
        );

        return response()->json(['data' => $this->serializeRole($role->load('permissions'))], 201);
    }

    public function update(Request $request, Role $group, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.groups.update');

        $before = $this->auditValues($group->load('permissions'));
        $data = $request->validate($this->rules(ignoreId: $group->id, requireName: false));
        unset($data['permissions']);

        if (array_key_exists('name', $data)) {
            $data['display_name'] = $data['name'];
            unset($data['name']);
        }

        $group->update($data);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.groups.update',
            module: 'system.groups',
            subject: $group,
            before: $before,
            after: $this->auditValues($group->fresh()->load('permissions')),
        );

        return response()->json(['data' => $this->serializeRole($group->fresh()->load('permissions'))]);
    }

    public function syncPermissionMatrix(Request $request, Role $group, AuditLogger $auditLogger, PermissionCatalog $permissionCatalog): JsonResponse
    {
        $this->authorizePermission($request, 'system.groups.update');

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);
        $before = $this->auditValues($group->load('permissions'));
        $this->syncPermissions($group, $data['permissions'] ?? [], $permissionCatalog);

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.groups.permissions.update',
            module: 'system.groups',
            subject: $group,
            before: $before,
            after: $this->auditValues($group->fresh()->load('permissions')),
        );

        return response()->json(['data' => $this->serializeRole($group->fresh()->load('permissions'))]);
    }

    public function destroy(Request $request, Role $group, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.groups.delete');

        if ((bool) $group->is_system) {
            throw ValidationException::withMessages([
                'group' => ['system_group_delete_forbidden'],
            ]);
        }

        $before = $this->auditValues($group->load('permissions'));
        $groupId = $group->id;

        $group->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.groups.delete',
            module: 'system.groups',
            subject: $group,
            before: $before,
            after: ['deleted' => true, 'id' => $groupId],
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function rules(?int $ignoreId = null, bool $requireName = true): array
    {
        return [
            'name' => [
                $requireName ? 'required' : 'sometimes',
                'string',
                'max:255',
                Rule::unique('roles', 'display_name')
                    ->where(fn ($query) => $query->where('guard_name', 'web'))
                    ->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string'],
            'is_system' => ['boolean'],
            'status' => ['required', 'in:active,disabled'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ];
    }

    private function uniqueRoleName(string $displayName): string
    {
        $base = Str::slug($displayName, '_');
        $base = $base === '' ? 'group' : $base;
        $candidate = $base;
        $counter = 2;

        while (Role::query()->where('name', $candidate)->where('guard_name', 'web')->exists()) {
            $candidate = "{$base}_{$counter}";
            $counter++;
        }

        return $candidate;
    }

    private function syncPermissions(Role $role, array $permissionNames, PermissionCatalog $permissionCatalog): void
    {
        $allowedNames = collect($permissionCatalog->permissionNames());
        $validNames = collect($permissionNames)
            ->unique()
            ->values()
            ->each(fn (string $permission) => abort_unless($allowedNames->contains($permission), 422));

        $role->syncPermissions($validNames->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function serializeRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'key' => $role->name,
            'name' => $role->display_name ?: $role->name,
            'description' => $role->description,
            'is_system' => (bool) $role->is_system,
            'status' => $role->status,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];
    }

    private function auditValues(Role $role): array
    {
        return $this->serializeRole($role);
    }
}

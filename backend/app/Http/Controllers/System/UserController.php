<?php

namespace App\Http\Controllers\System;

use App\Actions\System\LockUserAction;
use App\Actions\System\ResetUserPasswordAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PermissionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'system.users.read');

        $canReadPhone = $this->canReadPhone($request);
        $users = User::query()
            ->with(['department', 'roles'])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->integer('department_id')))
            ->when($request->filled('group_id'), fn ($query) => $query->whereHas('roles', fn ($query) => $query->whereKey($request->integer('group_id'))))
            ->orderBy('id')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $users->getCollection()
                ->map(fn (User $user): array => $this->serializeUser($user, $canReadPhone))
                ->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'fields' => [
                    'phone' => ['read' => (bool) $canReadPhone],
                ],
            ],
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission($request, 'system.users.read');

        $canReadPhone = $this->canReadPhone($request);

        return response()->json([
            'data' => $this->serializeUser($user->load(['department', 'roles']), $canReadPhone),
            'meta' => ['fields' => ['phone' => ['read' => (bool) $canReadPhone]]],
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.users.create');
        $this->authorizePhoneUpdate($request);

        $data = $request->validate($this->storeRules());
        $groupIds = $data['group_ids'] ?? [];
        unset($data['group_ids']);

        $data['password_changed_at'] = Carbon::now();
        $user = User::query()->create($data);
        $user->syncRoles($groupIds);
        $user->load(['department', 'roles']);

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.users.create',
            module: 'system.users',
            subject: $user,
            after: $this->auditValues($user),
        );

        return response()->json(['data' => $this->serializeUser($user, true)], 201);
    }

    public function update(Request $request, User $user, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.users.update');
        $this->authorizePhoneUpdate($request);

        $before = $this->auditValues($user->load('roles'));
        $data = $request->validate($this->updateRules($user->id));
        $groupIds = $data['group_ids'] ?? null;
        unset($data['group_ids']);

        $user->update($data);

        if ($groupIds !== null) {
            $user->syncRoles($groupIds);
        }

        $user = $user->fresh()->load(['department', 'roles']);

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.users.update',
            module: 'system.users',
            subject: $user,
            before: $before,
            after: $this->auditValues($user),
        );

        return response()->json(['data' => $this->serializeUser($user, true)]);
    }

    public function lock(Request $request, User $user, LockUserAction $lockUserAction, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.users.update');

        $before = $this->auditValues($user);
        $user = $lockUserAction->execute($user, $request->string('reason')->toString() ?: null);

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.users.lock',
            module: 'system.users',
            subject: $user,
            before: $before,
            after: $this->auditValues($user),
        );

        return response()->json(['data' => $this->serializeUser($user->load(['department', 'roles']), true)]);
    }

    public function unlock(Request $request, User $user, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.users.update');

        $before = $this->auditValues($user);
        $user->forceFill([
            'status' => 'active',
            'locked_at' => null,
            'lock_reason' => null,
            'failed_login_attempts' => 0,
        ])->save();
        $user = $user->fresh();

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.users.unlock',
            module: 'system.users',
            subject: $user,
            before: $before,
            after: $this->auditValues($user),
        );

        return response()->json(['data' => $this->serializeUser($user->load(['department', 'roles']), true)]);
    }

    public function resetPassword(Request $request, User $user, ResetUserPasswordAction $resetUserPasswordAction, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.users.update');

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
            'must_change_password' => ['boolean'],
        ]);
        $before = $this->auditValues($user);
        $user = $resetUserPasswordAction->execute(
            user: $user,
            password: $data['password'],
            mustChangePassword: $data['must_change_password'] ?? true,
        );

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.users.reset_password',
            module: 'system.users',
            subject: $user,
            before: $before,
            after: $this->auditValues($user),
        );

        return response()->json([
            'data' => $this->serializeUser($user->load(['department', 'roles']), true),
            'meta' => [
                'temporary_password' => $data['password'],
            ],
        ]);
    }

    private function authorizePhoneUpdate(Request $request): void
    {
        if ($request->has('phone')) {
            $this->authorizePermission($request, 'system.users.field.phone.update');
        }
    }

    private function canReadPhone(Request $request): bool
    {
        return app(PermissionAccess::class)->userCan($request->user(), 'system.users.field.phone.read');
    }

    private function storeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:32'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['required', 'in:active,disabled,locked'],
            'must_change_password' => ['boolean'],
            'group_ids' => ['array'],
            'group_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }

    private function updateRules(int $userId): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', "unique:users,email,{$userId}"],
            'phone' => ['nullable', 'string', 'max:32'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['required', 'in:active,disabled,locked'],
            'must_change_password' => ['boolean'],
            'group_ids' => ['array'],
            'group_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }

    private function serializeUser(User $user, bool $canReadPhone): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $canReadPhone ? $user->phone : null,
            'department' => $user->department,
            'status' => $user->status,
            'must_change_password' => $user->must_change_password,
            'locked_at' => $user->locked_at,
            'lock_reason' => $user->lock_reason,
            'groups' => $user->roles->map(fn ($role): array => [
                'id' => $role->id,
                'name' => $role->display_name ?: $role->name,
                'key' => $role->name,
                'status' => $role->status,
            ])->values(),
        ];
    }

    private function auditValues(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'department_id' => $user->department_id,
            'status' => $user->status,
            'must_change_password' => $user->must_change_password,
            'locked_at' => $user->locked_at?->toDateTimeString(),
            'lock_reason' => $user->lock_reason,
            'group_ids' => $user->roles->pluck('id')->sort()->values()->all(),
        ];
    }
}

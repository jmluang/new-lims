<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'system.departments.read');

        $departments = Department::query()
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $departments]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.departments.create');

        $data = $request->validate($this->rules());
        $department = Department::query()->create($data);

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.departments.create',
            module: 'system.departments',
            subject: $department,
            after: $department->only(['parent_id', 'name', 'code', 'sort_order', 'status']),
        );

        return response()->json(['data' => $department], 201);
    }

    public function update(Request $request, Department $department, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.departments.update');

        $before = $department->only(['parent_id', 'name', 'code', 'sort_order', 'status']);
        $department->update($request->validate($this->rules(ignoreId: $department->id)));
        $department = $department->fresh();

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.departments.update',
            module: 'system.departments',
            subject: $department,
            before: $before,
            after: $department->only(['parent_id', 'name', 'code', 'sort_order', 'status']),
        );

        return response()->json(['data' => $department]);
    }

    public function destroy(Request $request, Department $department, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.departments.delete');

        $before = $department->only(['parent_id', 'name', 'code', 'sort_order', 'status']);
        $department->update(['status' => 'disabled']);

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.departments.disable',
            module: 'system.departments',
            subject: $department,
            before: $before,
            after: $department->fresh()->only(['parent_id', 'name', 'code', 'sort_order', 'status']),
        );

        return response()->json(['data' => $department->fresh()]);
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:departments,code'.($ignoreId ? ",{$ignoreId}" : '')],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:active,disabled'],
        ];
    }
}

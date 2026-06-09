<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentSystem;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EquipmentSystemController extends Controller
{
    private const RESOURCE = 'equipment_systems';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_systems.read', self::RESOURCE);

        return response()->json([
            'data' => EquipmentSystem::query()
                ->withCount('equipment')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_systems.create', self::RESOURCE);

        $validated = $request->validate($this->rules());
        $system = EquipmentSystem::query()->create($validated);
        
        // Handle equipment assignment if equipment_ids is provided
        if ($request->has('equipment_ids')) {
            $equipmentIds = $request->input('equipment_ids', []);
            Equipment::query()
                ->whereIn('id', $equipmentIds)
                ->update(['system_id' => $system->id]);
        }

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_systems.create',
            module: self::RESOURCE,
            subject: $system,
            after: $this->auditValues($system),
        );

        return response()->json(['data' => $system->loadCount('equipment')], 201);
    }

    public function update(Request $request, EquipmentSystem $equipmentSystem, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_systems.update', self::RESOURCE, $equipmentSystem);

        $before = $this->auditValues($equipmentSystem);
        $validated = $request->validate($this->rules($equipmentSystem->id, true, $request));
        
        $equipmentSystem->update($validated);
        
        // Handle equipment assignment if equipment_ids is provided
        if ($request->has('equipment_ids')) {
            $equipmentIds = $request->input('equipment_ids', []);
            
            // Remove system_id from equipment not in the list
            Equipment::query()
                ->where('system_id', $equipmentSystem->id)
                ->whereNotIn('id', $equipmentIds)
                ->update(['system_id' => null]);
            
            // Add system_id to equipment in the list
            Equipment::query()
                ->whereIn('id', $equipmentIds)
                ->update(['system_id' => $equipmentSystem->id]);
        }

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_systems.update',
            module: self::RESOURCE,
            subject: $equipmentSystem,
            before: $before,
            after: $this->auditValues($equipmentSystem->fresh()),
        );

        return response()->json(['data' => $equipmentSystem->fresh()->loadCount('equipment')]);
    }

    public function destroy(Request $request, EquipmentSystem $equipmentSystem, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_systems.delete', self::RESOURCE, $equipmentSystem);

        if ($equipmentSystem->equipment()->exists()) {
            throw ValidationException::withMessages([
                'system' => ['system_has_equipment'],
            ]);
        }

        $before = $this->auditValues($equipmentSystem);
        $equipmentSystem->update(['status' => 'disabled']);

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_systems.disable',
            module: self::RESOURCE,
            subject: $equipmentSystem,
            before: $before,
            after: $this->auditValues($equipmentSystem->fresh()),
        );

        return response()->json(['data' => $equipmentSystem->fresh()->loadCount('equipment')]);
    }

    private function rules(?int $ignoreId = null, bool $isUpdate = false, Request $request = null): array
    {
        $rules = [
            'equipment_ids' => ['nullable', 'array'],
            'equipment_ids.*' => ['integer', 'exists:equipment,id'],
        ];
        
        // When updating, these fields are only required if they are being updated
        if (!$isUpdate || ($request && $request->has('name'))) {
            $rules['name'] = ['required', 'string', 'max:255'];
        }
        
        if (!$isUpdate || ($request && $request->has('code'))) {
            $rules['code'] = ['required', 'string', 'max:255', 'unique:equipment_systems,code'.($ignoreId ? ",{$ignoreId}" : '')];
        }
        
        if (!$isUpdate || ($request && $request->has('status'))) {
            $rules['status'] = ['required', 'in:active,disabled'];
        }
        
        return $rules;
    }

    private function auditValues(EquipmentSystem $system): array
    {
        return $system->only(['id', 'name', 'code', 'status']);
    }
}

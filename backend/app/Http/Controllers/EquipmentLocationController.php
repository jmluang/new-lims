<?php

namespace App\Http\Controllers;

use App\Models\EquipmentLocation;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EquipmentLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_locations.read', 'equipment_locations');

        return response()->json([
            'data' => EquipmentLocation::query()
                ->whereNull('parent_id')
                ->with('children')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_locations.create', 'equipment_locations');

        $location = EquipmentLocation::query()->create($request->validate($this->rules()));

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_locations.create',
            module: 'equipment_locations',
            subject: $location,
            after: $location->only(['parent_id', 'name', 'code', 'sort_order', 'status']),
        );

        return response()->json(['data' => $location], 201);
    }

    public function update(Request $request, EquipmentLocation $equipmentLocation, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_locations.update', 'equipment_locations', $equipmentLocation);

        $before = $equipmentLocation->only(['parent_id', 'name', 'code', 'sort_order', 'status']);
        $equipmentLocation->update($request->validate($this->rules(ignoreId: $equipmentLocation->id)));

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_locations.update',
            module: 'equipment_locations',
            subject: $equipmentLocation,
            before: $before,
            after: $equipmentLocation->fresh()->only(['parent_id', 'name', 'code', 'sort_order', 'status']),
        );

        return response()->json(['data' => $equipmentLocation->fresh()]);
    }

    public function destroy(Request $request, EquipmentLocation $equipmentLocation, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_locations.delete', 'equipment_locations', $equipmentLocation);

        if ($equipmentLocation->equipment()->exists()) {
            throw ValidationException::withMessages([
                'location' => ['location_has_equipment'],
            ]);
        }

        $before = $equipmentLocation->only(['parent_id', 'name', 'code', 'sort_order', 'status']);
        $equipmentLocation->update(['status' => 'disabled']);

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_locations.disable',
            module: 'equipment_locations',
            subject: $equipmentLocation,
            before: $before,
            after: $equipmentLocation->fresh()->only(['parent_id', 'name', 'code', 'sort_order', 'status']),
        );

        return response()->json(['data' => $equipmentLocation->fresh()]);
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:equipment_locations,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:equipment_locations,code'.($ignoreId ? ",{$ignoreId}" : '')],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:active,disabled'],
        ];
    }
}

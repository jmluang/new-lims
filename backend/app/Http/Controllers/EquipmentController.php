<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentLocation;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\FieldPermissionFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EquipmentController extends Controller
{
    private const RESOURCE = 'equipment';

    public function index(Request $request, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'equipment.read', self::RESOURCE);

        $query = $this->filteredQuery($request)->with('location')->orderBy('id');

        $equipment = $query->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $equipment->getCollection()
                ->map(fn (Equipment $equipment): array => $this->serializeEquipment($equipment, $request, $fieldPermissionFilter))
                ->values(),
            'meta' => [
                'current_page' => $equipment->currentPage(),
                'per_page' => $equipment->perPage(),
                'total' => $equipment->total(),
                'fields' => $fieldPermissionFilter->meta($request->user(), self::RESOURCE),
            ],
        ]);
    }

    public function show(Request $request, Equipment $equipment, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'equipment.read', self::RESOURCE, $equipment);

        return response()->json([
            'data' => $this->serializeEquipment($equipment->load('location'), $request, $fieldPermissionFilter),
            'meta' => ['fields' => $fieldPermissionFilter->meta($request->user(), self::RESOURCE)],
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'equipment.create', self::RESOURCE);
        $this->rejectForbiddenSensitiveFields($request, $fieldPermissionFilter);
        $this->rejectDisabledLocation($request);

        $equipment = Equipment::query()->create($request->validate($this->rules()));

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment.create',
            module: self::RESOURCE,
            subject: $equipment,
            after: $this->auditValues($equipment),
        );

        return response()->json(['data' => $this->serializeEquipment($equipment, $request, $fieldPermissionFilter)], 201);
    }

    public function update(Request $request, Equipment $equipment, AuditLogger $auditLogger, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'equipment.update', self::RESOURCE, $equipment);
        $this->rejectForbiddenSensitiveFields($request, $fieldPermissionFilter, $equipment);
        $this->rejectDisabledLocation($request);

        $before = $this->auditValues($equipment);
        $equipment->update($request->validate($this->rules($equipment->id, requireEquipmentNo: false)));

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment.update',
            module: self::RESOURCE,
            subject: $equipment,
            before: $before,
            after: $this->auditValues($equipment->fresh()),
        );

        return response()->json(['data' => $this->serializeEquipment($equipment->fresh(), $request, $fieldPermissionFilter)]);
    }

    public function destroy(Request $request, Equipment $equipment, AuditLogger $auditLogger, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'equipment.delete', self::RESOURCE, $equipment);

        $before = $this->auditValues($equipment);
        $equipment->update(['status' => 'disabled']);

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment.delete',
            module: self::RESOURCE,
            subject: $equipment,
            before: $before,
            after: $this->auditValues($equipment->fresh()),
        );

        return response()->json(['data' => $this->serializeEquipment($equipment->fresh()->load('location'), $request, $fieldPermissionFilter)]);
    }

    /**
     * @return Builder<Equipment>
     */
    private function filteredQuery(Request $request): Builder
    {
        return Equipment::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('equipment_no', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn (Builder $query): Builder => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('location_id'), fn (Builder $query): Builder => $query->where('location_id', $request->integer('location_id')))
            ->when($request->filled('manufacturer'), fn (Builder $query): Builder => $query->where('manufacturer', 'like', '%'.$request->string('manufacturer')->toString().'%'))
            ->when($request->filled('calibration_due_from'), fn (Builder $query): Builder => $query->whereDate('next_calibration_date', '>=', $request->date('calibration_due_from')))
            ->when($request->filled('calibration_due_to'), fn (Builder $query): Builder => $query->whereDate('next_calibration_date', '<=', $request->date('calibration_due_to')));
    }

    private function rejectForbiddenSensitiveFields(Request $request, FieldPermissionFilter $fieldPermissionFilter, ?Equipment $equipment = null): void
    {
        $forbiddenFields = $fieldPermissionFilter->forbiddenUpdateFields($request->user(), self::RESOURCE, $request->all());

        if ($forbiddenFields === []) {
            return;
        }

        app(AuditLogger::class)->record(
            actor: $request->user(),
            action: 'authorization.denied',
            module: self::RESOURCE,
            subject: $equipment,
            after: ['denied_fields' => $forbiddenFields],
        );

        throw ValidationException::withMessages(collect($forbiddenFields)->mapWithKeys(
            fn (string $field): array => [$field => ['field_update_forbidden']]
        )->all());
    }

    private function rejectDisabledLocation(Request $request): void
    {
        if (! $request->filled('location_id')) {
            return;
        }

        $location = EquipmentLocation::query()->find($request->integer('location_id'));

        if ($location?->status === 'disabled') {
            throw ValidationException::withMessages([
                'location_id' => ['disabled_location_forbidden'],
            ]);
        }
    }

    private function rules(?int $equipmentId = null, bool $requireEquipmentNo = true): array
    {
        return [
            'equipment_no' => [$requireEquipmentNo ? 'required' : 'sometimes', 'string', 'max:255', 'unique:equipment,equipment_no'.($equipmentId ? ",{$equipmentId}" : '')],
            'name' => [$requireEquipmentNo ? 'required' : 'sometimes', 'string', 'max:255'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_no' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer', 'exists:equipment_locations,id'],
            'legacy_placement' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'enable_date' => ['nullable', 'date'],
            'calibration_date' => ['nullable', 'date'],
            'calibration_duration' => ['nullable', 'string', 'max:255'],
            'next_calibration_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,disabled,maintenance,retired'],
            'device_image' => ['nullable', 'string', 'max:255'],
            'manual_files' => ['nullable', 'array'],
            'instruction_files' => ['nullable', 'array'],
            'calibration_files' => ['nullable', 'array'],
            'other_files' => ['nullable', 'array'],
            'remark' => ['nullable', 'string'],
        ];
    }

    private function serializeEquipment(Equipment $equipment, Request $request, FieldPermissionFilter $fieldPermissionFilter): array
    {
        $record = $this->auditValues($equipment);
        $record['location'] = $equipment->location;

        return $fieldPermissionFilter->filterRecord($request->user(), self::RESOURCE, $record);
    }

    private function auditValues(Equipment $equipment): array
    {
        return [
            'id' => $equipment->id,
            'equipment_no' => $equipment->equipment_no,
            'name' => $equipment->name,
            'manufacturer' => $equipment->manufacturer,
            'model' => $equipment->model,
            'serial_no' => $equipment->serial_no,
            'location_id' => $equipment->location_id,
            'legacy_placement' => $equipment->legacy_placement,
            'purchase_date' => $equipment->purchase_date?->toDateString(),
            'enable_date' => $equipment->enable_date?->toDateString(),
            'calibration_date' => $equipment->calibration_date?->toDateString(),
            'calibration_duration' => $equipment->calibration_duration,
            'next_calibration_date' => $equipment->next_calibration_date?->toDateString(),
            'status' => $equipment->status,
            'device_image' => $equipment->device_image,
            'manual_files' => $equipment->manual_files,
            'instruction_files' => $equipment->instruction_files,
            'calibration_files' => $equipment->calibration_files,
            'other_files' => $equipment->other_files,
            'remark' => $equipment->remark,
        ];
    }
}

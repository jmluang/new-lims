<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentCalibration;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EquipmentCalibrationController extends Controller
{
    private const RESOURCE = 'equipment_calibrations';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_calibrations.read', self::RESOURCE);

        $records = EquipmentCalibration::query()
            ->withCount(['devices', 'standards'])
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where('calibration_name', 'like', "%{$search}%");
            })
            ->when($request->filled('result'), fn (Builder $query): Builder => $query->where('result', $request->string('result')->toString()))
            ->when($request->filled('date_from'), fn (Builder $query): Builder => $query->where('calibration_time', '>=', $request->string('date_from')->toString()))
            ->when($request->filled('date_to'), fn (Builder $query): Builder => $query->where('calibration_time', '<=', $request->string('date_to')->toString().' 23:59:59'))
            ->orderByDesc('calibration_time')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $records->getCollection()->map(fn (EquipmentCalibration $record): array => $this->serializeSummary($record))->values(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_calibrations.create', self::RESOURCE);

        $payload = $request->validate($this->rules());

        $record = DB::transaction(function () use ($request, $payload): EquipmentCalibration {
            $record = EquipmentCalibration::query()->create([
                'calibration_project_id' => $payload['calibration_project_id'] ?? null,
                'calibration_name' => $payload['calibration_name'],
                'calibration_time' => $payload['calibration_time'],
                'operator_id' => $request->user()?->id,
                'operator_name' => $payload['operator_name'] ?? $request->user()?->name,
                'result' => $payload['result'] ?? 'qualified',
                'remark' => $payload['remark'] ?? null,
                'attachment_files' => $payload['attachment_files'] ?? [],
                'photo_files' => $payload['photo_files'] ?? [],
            ]);

            $this->syncChildren($record, $payload);

            return $record;
        });

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_calibrations.create',
            module: self::RESOURCE,
            subject: $record,
            after: $this->serialize($record->fresh(['devices', 'standards'])),
        );

        return response()->json(['data' => $this->serialize($record->fresh(['devices', 'standards']))], 201);
    }

    public function show(Request $request, EquipmentCalibration $equipmentCalibration): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_calibrations.read', self::RESOURCE, $equipmentCalibration);

        return response()->json(['data' => $this->serialize($equipmentCalibration->load('devices', 'standards'))]);
    }

    public function update(Request $request, EquipmentCalibration $equipmentCalibration, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_calibrations.update', self::RESOURCE, $equipmentCalibration);

        $payload = $request->validate($this->rules());
        $before = $this->serialize($equipmentCalibration->load('devices', 'standards'));

        DB::transaction(function () use ($equipmentCalibration, $payload): void {
            $equipmentCalibration->update([
                'calibration_project_id' => $payload['calibration_project_id'] ?? null,
                'calibration_name' => $payload['calibration_name'],
                'calibration_time' => $payload['calibration_time'],
                'operator_name' => $payload['operator_name'] ?? $equipmentCalibration->operator_name,
                'result' => $payload['result'] ?? 'qualified',
                'remark' => $payload['remark'] ?? null,
                'attachment_files' => $payload['attachment_files'] ?? [],
                'photo_files' => $payload['photo_files'] ?? [],
            ]);

            $equipmentCalibration->devices()->delete();
            $equipmentCalibration->standards()->delete();
            $this->syncChildren($equipmentCalibration, $payload);
        });

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_calibrations.update',
            module: self::RESOURCE,
            subject: $equipmentCalibration,
            before: $before,
            after: $this->serialize($equipmentCalibration->fresh(['devices', 'standards'])),
        );

        return response()->json(['data' => $this->serialize($equipmentCalibration->fresh(['devices', 'standards']))]);
    }

    public function destroy(Request $request, EquipmentCalibration $equipmentCalibration, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_calibrations.delete', self::RESOURCE, $equipmentCalibration);

        $before = $this->serialize($equipmentCalibration->load('devices', 'standards'));
        $equipmentCalibration->delete();

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_calibrations.delete',
            module: self::RESOURCE,
            before: $before,
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncChildren(EquipmentCalibration $record, array $payload): void
    {
        $equipmentById = $this->equipmentMap($payload);

        foreach ($payload['devices'] ?? [] as $device) {
            $equipment = isset($device['equipment_id']) ? $equipmentById->get($device['equipment_id']) : null;

            $record->devices()->create([
                'equipment_id' => $device['equipment_id'] ?? null,
                'equipment_no' => $device['equipment_no'] ?? $equipment?->equipment_no ?? '',
                'equipment_name' => $device['equipment_name'] ?? $equipment?->name ?? '',
                'equipment_model' => $device['equipment_model'] ?? $equipment?->model,
                'calibration_date' => $device['calibration_date'] ?? null,
                'remark' => $device['remark'] ?? null,
            ]);
        }

        foreach ($payload['standards'] ?? [] as $standard) {
            $equipment = isset($standard['equipment_id']) ? $equipmentById->get($standard['equipment_id']) : null;

            $record->standards()->create([
                'equipment_id' => $standard['equipment_id'] ?? null,
                'standard_no' => $standard['standard_no'] ?? $equipment?->equipment_no ?? '',
                'standard_name' => $standard['standard_name'] ?? $equipment?->name ?? '',
                'standard_model' => $standard['standard_model'] ?? $equipment?->model,
                'calibration_date' => $standard['calibration_date'] ?? null,
                'remark' => $standard['remark'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return \Illuminate\Support\Collection<int, Equipment>
     */
    private function equipmentMap(array $payload)
    {
        $ids = collect($payload['devices'] ?? [])
            ->merge($payload['standards'] ?? [])
            ->pluck('equipment_id')
            ->filter()
            ->unique()
            ->values();

        return Equipment::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'calibration_project_id' => ['nullable', 'integer', 'exists:calibration_projects,id'],
            'calibration_name' => ['required', 'string', 'max:255'],
            'calibration_time' => ['required', 'date'],
            'operator_name' => ['nullable', 'string', 'max:255'],
            'result' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
            'attachment_files' => ['nullable', 'array'],
            'photo_files' => ['nullable', 'array'],
            'devices' => ['nullable', 'array'],
            'devices.*.equipment_id' => ['nullable', 'integer', 'exists:equipment,id'],
            'devices.*.equipment_no' => ['nullable', 'string', 'max:255'],
            'devices.*.equipment_name' => ['nullable', 'string', 'max:255'],
            'devices.*.equipment_model' => ['nullable', 'string', 'max:255'],
            'devices.*.calibration_date' => ['nullable', 'date'],
            'devices.*.remark' => ['nullable', 'string'],
            'standards' => ['nullable', 'array'],
            'standards.*.equipment_id' => ['nullable', 'integer', 'exists:equipment,id'],
            'standards.*.standard_no' => ['nullable', 'string', 'max:255'],
            'standards.*.standard_name' => ['nullable', 'string', 'max:255'],
            'standards.*.standard_model' => ['nullable', 'string', 'max:255'],
            'standards.*.calibration_date' => ['nullable', 'date'],
            'standards.*.remark' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(EquipmentCalibration $record): array
    {
        return [
            'id' => $record->id,
            'calibration_project_id' => $record->calibration_project_id,
            'calibration_name' => $record->calibration_name,
            'calibration_time' => $record->calibration_time?->format('Y-m-d H:i:s'),
            'operator_name' => $record->operator_name,
            'result' => $record->result,
            'devices_count' => $record->devices_count,
            'standards_count' => $record->standards_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EquipmentCalibration $record): array
    {
        return [
            'id' => $record->id,
            'calibration_project_id' => $record->calibration_project_id,
            'calibration_name' => $record->calibration_name,
            'calibration_time' => $record->calibration_time?->format('Y-m-d H:i:s'),
            'operator_id' => $record->operator_id,
            'operator_name' => $record->operator_name,
            'result' => $record->result,
            'remark' => $record->remark,
            'attachment_files' => $record->attachment_files ?? [],
            'photo_files' => $record->photo_files ?? [],
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
            'devices' => $record->devices->map(fn ($device): array => [
                'id' => $device->id,
                'equipment_id' => $device->equipment_id,
                'equipment_no' => $device->equipment_no,
                'equipment_name' => $device->equipment_name,
                'equipment_model' => $device->equipment_model,
                'calibration_date' => $device->calibration_date?->toDateString(),
                'remark' => $device->remark,
            ])->values(),
            'standards' => $record->standards->map(fn ($standard): array => [
                'id' => $standard->id,
                'equipment_id' => $standard->equipment_id,
                'standard_no' => $standard->standard_no,
                'standard_name' => $standard->standard_name,
                'standard_model' => $standard->standard_model,
                'calibration_date' => $standard->calibration_date?->toDateString(),
                'remark' => $standard->remark,
            ])->values(),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\TempHumidityRecord;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PermissionAccess;
use App\Services\Equipment\EquipmentPlacementResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TempHumidityRecordController extends Controller
{
    private const RESOURCE = 'temp_humidity_records';

    private const EQUIPMENT_COLUMNS = [
        'id',
        'equipment_no',
        'name',
        'model',
        'status',
        'calibration_date',
        'next_calibration_date',
    ];

    /**
     * Public device ingest endpoint. Ports the legacy example/post.php so a
     * temperature/humidity sensor can push a reading by GET or POST with the
     * fields temperature, humidity and equip_no. Returns the stored record.
     */
    public function ingest(Request $request, EquipmentPlacementResolver $placementResolver): JsonResponse
    {
        $data = $request->validate([
            'temperature' => ['required', 'numeric'],
            'humidity' => ['required', 'numeric'],
            'equip_no' => ['required', 'string', 'max:255'],
            'location_site' => ['nullable', 'string', 'max:255'],
            'location_room' => ['nullable', 'string', 'max:255'],
            'record_person' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
            'record_time' => ['nullable', 'date'],
        ]);

        $matchedEquipment = $this->equipmentForCode($data['equip_no'] ?? null) !== null;
        $data = $this->enrichPayloadWithEquipment($data, $placementResolver);

        if (! $matchedEquipment && ($data['location_site'] ?? null) === null && ($data['location_room'] ?? null) === null) {
            $data = [
                ...$data,
                ...$placementResolver->legacyDeviceDefaults(),
            ];
        }

        $data['record_person'] = $data['record_person'] ?? '设备自动';
        $data['remark'] = $data['remark'] ?? null;
        $data['record_time'] = $data['record_time'] ?? now();

        $record = TempHumidityRecord::query()->create($data);

        return response()->json([
            'status' => 'ok',
            'data' => $this->serialize($this->loadEquipmentRelations($record)),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'temp_humidity_records.read', self::RESOURCE);

        $records = $this->filteredQuery($request)
            ->with($this->equipmentRelations())
            ->orderByDesc('record_time')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 30));

        return response()->json([
            'data' => $records->getCollection()
                ->map(fn (TempHumidityRecord $record): array => $this->serialize($record))
                ->values(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }

    public function store(
        Request $request,
        AuditLogger $auditLogger,
        EquipmentPlacementResolver $placementResolver,
    ): JsonResponse {
        $this->authorizePermission($request, 'temp_humidity_records.create', self::RESOURCE);

        $data = $request->validate($this->rules());
        $data = $this->enrichPayloadWithEquipment($data, $placementResolver);
        $data['record_time'] = $data['record_time'] ?? now();

        $record = $this->loadEquipmentRelations(TempHumidityRecord::query()->create($data));

        $auditLogger->record(
            actor: $request->user(),
            action: 'temp_humidity_records.create',
            module: self::RESOURCE,
            subject: $record,
            after: $this->auditValues($record),
        );

        return response()->json(['data' => $this->serialize($record)], 201);
    }

    public function update(
        Request $request,
        TempHumidityRecord $tempHumidityRecord,
        AuditLogger $auditLogger,
        EquipmentPlacementResolver $placementResolver,
    ): JsonResponse {
        $this->authorizePermission($request, 'temp_humidity_records.update', self::RESOURCE, $tempHumidityRecord);

        $before = $this->auditValues($tempHumidityRecord);
        $data = $request->validate($this->updateRules());
        $data = $this->enrichPayloadWithEquipment($data, $placementResolver, $tempHumidityRecord);
        $tempHumidityRecord->update($data);
        $freshRecord = $this->loadEquipmentRelations($tempHumidityRecord->fresh());

        $auditLogger->record(
            actor: $request->user(),
            action: 'temp_humidity_records.update',
            module: self::RESOURCE,
            subject: $tempHumidityRecord,
            before: $before,
            after: $this->auditValues($freshRecord),
        );

        return response()->json(['data' => $this->serialize($freshRecord)]);
    }

    public function destroy(Request $request, TempHumidityRecord $tempHumidityRecord, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'temp_humidity_records.delete', self::RESOURCE, $tempHumidityRecord);

        $before = $this->auditValues($tempHumidityRecord);
        $tempHumidityRecord->delete();

        $auditLogger->record(
            actor: $request->user(),
            action: 'temp_humidity_records.delete',
            module: self::RESOURCE,
            subject: $tempHumidityRecord,
            before: $before,
        );

        return response()->json(['data' => ['id' => $before['id']]]);
    }

    public function equipmentLookup(Request $request, EquipmentPlacementResolver $placementResolver): JsonResponse
    {
        $this->authorizeLookupPermission($request);

        $data = $request->validate(['equip_no' => ['required', 'string', 'max:255']]);
        $equipment = $this->equipmentForCode($data['equip_no']);

        if (! $equipment) {
            throw (new ModelNotFoundException())->setModel(Equipment::class);
        }

        return response()->json(['data' => $this->serializeEquipmentLookup($equipment, $placementResolver)]);
    }

    /**
     * @return Builder<TempHumidityRecord>
     */
    private function filteredQuery(Request $request): Builder
    {
        return TempHumidityRecord::query()
            ->when($request->filled('equip_no'), fn (Builder $query): Builder => $query->where('equip_no', $request->string('equip_no')->toString()))
            ->when($request->filled('record_time_from'), fn (Builder $query): Builder => $query->whereDate('record_time', '>=', $request->input('record_time_from')))
            ->when($request->filled('record_time_to'), fn (Builder $query): Builder => $query->whereDate('record_time', '<=', $request->input('record_time_to')))
            ->when($request->filled('temperature_min'), fn (Builder $query): Builder => $query->where('temperature', '>=', (float) $request->input('temperature_min')))
            ->when($request->filled('temperature_max'), fn (Builder $query): Builder => $query->where('temperature', '<=', (float) $request->input('temperature_max')))
            ->when($request->filled('humidity_min'), fn (Builder $query): Builder => $query->where('humidity', '>=', (float) $request->input('humidity_min')))
            ->when($request->filled('humidity_max'), fn (Builder $query): Builder => $query->where('humidity', '<=', (float) $request->input('humidity_max')))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('equip_no', 'like', "%{$search}%")
                    ->orWhere('location_site', 'like', "%{$search}%")
                    ->orWhere('location_room', 'like', "%{$search}%")
                    ->orWhere('record_person', 'like', "%{$search}%"));
            });
    }

    private function rules(): array
    {
        return [
            'location_site' => ['required', 'string', 'max:255'],
            'location_room' => ['required', 'string', 'max:255'],
            'equip_no' => ['nullable', 'string', 'max:255'],
            'temperature' => ['nullable', 'numeric'],
            'humidity' => ['nullable', 'numeric'],
            'record_person' => ['required', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
            'record_time' => ['nullable', 'date'],
        ];
    }

    private function updateRules(): array
    {
        return [
            'location_site' => ['sometimes', 'required', 'string', 'max:255'],
            'location_room' => ['sometimes', 'required', 'string', 'max:255'],
            'equip_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'temperature' => ['sometimes', 'nullable', 'numeric'],
            'humidity' => ['sometimes', 'nullable', 'numeric'],
            'record_person' => ['sometimes', 'required', 'string', 'max:255'],
            'remark' => ['sometimes', 'nullable', 'string'],
            'record_time' => ['sometimes', 'required', 'date'],
        ];
    }

    private function equipmentForCode(?string $equipNo): ?Equipment
    {
        if ($equipNo === null || trim($equipNo) === '') {
            return null;
        }

        return Equipment::query()
            ->with('location.parent')
            ->where('equipment_no', trim($equipNo))
            ->first();
    }

    private function enrichPayloadWithEquipment(
        array $data,
        EquipmentPlacementResolver $placementResolver,
        ?TempHumidityRecord $existingRecord = null,
    ): array {
        if (array_key_exists('equip_no', $data) && ($data['equip_no'] === null || trim((string) $data['equip_no']) === '')) {
            return [
                ...$data,
                'equipment_id' => null,
            ];
        }

        $equipment = $this->equipmentForCode($data['equip_no'] ?? null);

        if (! $equipment) {
            return array_key_exists('equip_no', $data)
                ? [...$data, 'equipment_id' => null]
                : $data;
        }

        $placement = $placementResolver->resolve(
            $equipment,
            $data['location_site'] ?? null,
            $data['location_room'] ?? null,
        );

        return [
            ...$data,
            'equipment_id' => $equipment->id,
            'equip_no' => $equipment->equipment_no,
            'location_site' => $placement['location_site'] ?? $existingRecord?->location_site,
            'location_room' => $placement['location_room'] ?? $existingRecord?->location_room,
        ];
    }

    private function authorizeLookupPermission(Request $request): void
    {
        $permissionAccess = app(PermissionAccess::class);

        if ($permissionAccess->userCan($request->user(), 'temp_humidity_records.create')
            || $permissionAccess->userCan($request->user(), 'temp_humidity_records.update')) {
            return;
        }

        $this->authorizePermission($request, 'temp_humidity_records.create', self::RESOURCE);
    }

    private function auditValues(TempHumidityRecord $record): array
    {
        return [
            'id' => $record->id,
            'equipment_id' => $record->equipment_id,
            'equip_no' => $record->equip_no,
            'temperature' => $record->temperature,
            'humidity' => $record->humidity,
            'location_site' => $record->location_site,
            'location_room' => $record->location_room,
            'record_person' => $record->record_person,
            'remark' => $record->remark,
            'record_time' => $record->record_time?->toDateTimeString(),
        ];
    }

    private function serialize(TempHumidityRecord $record): array
    {
        $equipment = $record->equipment ?? $record->legacyEquipment;

        return [
            'id' => $record->id,
            'equipment_id' => $record->equipment_id,
            'equip_no' => $record->equip_no,
            'equipment_name' => $equipment?->name,
            'equipment' => $equipment ? [
                'id' => $equipment->id,
                'equipment_no' => $equipment->equipment_no,
                'name' => $equipment->name,
                'model' => $equipment->model,
                'status' => $equipment->status,
                'calibration_date' => $equipment->calibration_date?->toDateString(),
                'next_calibration_date' => $equipment->next_calibration_date?->toDateString(),
            ] : null,
            'temperature' => $record->temperature,
            'humidity' => $record->humidity,
            'location_site' => $record->location_site,
            'location_room' => $record->location_room,
            'record_person' => $record->record_person,
            'remark' => $record->remark,
            'record_time' => $record->record_time?->toDateTimeString(),
            'created_at' => $record->created_at?->toDateTimeString(),
        ];
    }

    private function serializeEquipmentLookup(Equipment $equipment, EquipmentPlacementResolver $placementResolver): array
    {
        $placement = $placementResolver->resolve($equipment);

        return [
            'id' => $equipment->id,
            'equipment_no' => $equipment->equipment_no,
            'name' => $equipment->name,
            'model' => $equipment->model,
            'status' => $equipment->status,
            'calibration_date' => $equipment->calibration_date?->toDateString(),
            'next_calibration_date' => $equipment->next_calibration_date?->toDateString(),
            'location_site' => $placement['location_site'],
            'location_room' => $placement['location_room'],
        ];
    }

    private function equipmentRelations(): array
    {
        return [
            'equipment' => fn ($query) => $query->select(self::EQUIPMENT_COLUMNS),
            'legacyEquipment' => fn ($query) => $query->select(self::EQUIPMENT_COLUMNS),
        ];
    }

    private function loadEquipmentRelations(TempHumidityRecord $record): TempHumidityRecord
    {
        return $record->load($this->equipmentRelations());
    }
}

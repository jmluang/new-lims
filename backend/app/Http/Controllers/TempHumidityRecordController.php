<?php

namespace App\Http\Controllers;

use App\Models\TempHumidityRecord;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TempHumidityRecordController extends Controller
{
    private const RESOURCE = 'temp_humidity_records';

    /**
     * Public device ingest endpoint. Ports the legacy example/post.php so a
     * temperature/humidity sensor can push a reading by GET or POST with the
     * fields temperature, humidity and equip_no. Returns the stored record.
     */
    public function ingest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temperature' => ['required', 'numeric'],
            'humidity' => ['required', 'numeric'],
            'equip_no' => ['required', 'string', 'max:255'],
            'location_site' => ['nullable', 'string', 'max:255'],
            'location_room' => ['nullable', 'string', 'max:255'],
            'record_person' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
            'record_time' => ['nullable', 'date'],
        ]);

        $record = TempHumidityRecord::query()->create([
            'equip_no' => $validated['equip_no'],
            'temperature' => $validated['temperature'],
            'humidity' => $validated['humidity'],
            'location_site' => $validated['location_site'] ?? null,
            'location_room' => $validated['location_room'] ?? null,
            'record_person' => $validated['record_person'] ?? '设备自动',
            'remark' => $validated['remark'] ?? null,
            'record_time' => $validated['record_time'] ?? now(),
        ]);

        return response()->json([
            'status' => 'ok',
            'data' => $this->serialize($record),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'temp_humidity_records.read', self::RESOURCE);

        $records = $this->filteredQuery($request)
            ->with('equipment:id,equipment_no,name')
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

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'temp_humidity_records.create', self::RESOURCE);

        $data = $request->validate($this->rules());
        $data['record_time'] = $data['record_time'] ?? now();

        $record = TempHumidityRecord::query()->create($data);

        $auditLogger->record(
            actor: $request->user(),
            action: 'temp_humidity_records.create',
            module: self::RESOURCE,
            subject: $record,
            after: $this->auditValues($record),
        );

        return response()->json(['data' => $this->serialize($record->load('equipment:id,equipment_no,name'))], 201);
    }

    public function update(Request $request, TempHumidityRecord $tempHumidityRecord, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'temp_humidity_records.update', self::RESOURCE, $tempHumidityRecord);

        $before = $this->auditValues($tempHumidityRecord);
        $tempHumidityRecord->update($request->validate($this->rules()));

        $auditLogger->record(
            actor: $request->user(),
            action: 'temp_humidity_records.update',
            module: self::RESOURCE,
            subject: $tempHumidityRecord,
            before: $before,
            after: $this->auditValues($tempHumidityRecord->fresh()),
        );

        return response()->json(['data' => $this->serialize($tempHumidityRecord->fresh()->load('equipment:id,equipment_no,name'))]);
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

    /**
     * @return Builder<TempHumidityRecord>
     */
    private function filteredQuery(Request $request): Builder
    {
        return TempHumidityRecord::query()
            ->when($request->filled('equip_no'), fn (Builder $query): Builder => $query->where('equip_no', $request->string('equip_no')->toString()))
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

    private function auditValues(TempHumidityRecord $record): array
    {
        return [
            'id' => $record->id,
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
        return [
            'id' => $record->id,
            'equip_no' => $record->equip_no,
            'equipment_name' => $record->equipment?->name,
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
}

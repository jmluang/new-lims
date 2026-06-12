<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentUsageRecord;
use App\Models\Sample;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EquipmentUsageRecordController extends Controller
{
    private const RESOURCE = 'equipment_usage_records';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_usage_records.read', self::RESOURCE);

        $records = $this->filteredQuery($request)
            ->orderByDesc('start_time')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $records->getCollection()->map(fn (EquipmentUsageRecord $record): array => $this->serializeRecord($record))->values(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'using_count' => EquipmentUsageRecord::query()->whereNull('end_time')->count(),
                'finished_count' => EquipmentUsageRecord::query()->whereNotNull('end_time')->count(),
            ],
        ]);
    }

    public function formOptions(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_usage_records.create', self::RESOURCE);

        return response()->json([
            'data' => [
                'equipment' => Equipment::query()
                    ->orderBy('equipment_no')
                    ->limit((int) $request->integer('limit', 100))
                    ->get()
                    ->map(fn (Equipment $equipment): array => $this->serializeEquipmentOption($equipment))
                    ->values(),
                'samples' => Sample::query()
                    ->orderByDesc('id')
                    ->limit((int) $request->integer('limit', 100))
                    ->get()
                    ->map(fn (Sample $sample): array => $this->serializeSampleOption($sample))
                    ->values(),
            ],
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_usage_records.create', self::RESOURCE);

        $payload = $request->validate([
            'type' => ['required', 'in:equipment,sample'],
            'code' => ['required', 'string', 'max:255'],
        ]);

        if ($payload['type'] === 'equipment') {
            $equipment = Equipment::query()->where('equipment_no', $payload['code'])->firstOrFail();

            return response()->json(['data' => $this->serializeEquipmentOption($equipment)]);
        }

        $sample = Sample::query()->where('sample_no', $payload['code'])->firstOrFail();

        return response()->json(['data' => $this->serializeSampleOption($sample)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEquipmentOption(Equipment $equipment): array
    {
        return [
            'id' => $equipment->id,
            'equipment_no' => $equipment->equipment_no,
            'name' => $equipment->name,
            'model' => $equipment->model,
            'status' => $equipment->status,
            'calibration_date' => $equipment->calibration_date?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSampleOption(Sample $sample): array
    {
        return [
            'id' => $sample->id,
            'sample_no' => $sample->sample_no,
            'sample_name' => $sample->sample_name,
            'model' => $sample->model,
            'status' => $sample->status,
        ];
    }

    public function start(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_usage_records.create', self::RESOURCE);

        $payload = $request->validate([
            'equipment_ids' => ['required', 'array', 'min:1'],
            'equipment_ids.*' => ['integer', 'exists:equipment,id'],
            'sample_ids' => ['required', 'array', 'min:1'],
            'sample_ids.*' => ['integer', 'exists:samples,id'],
            'start_time' => ['nullable', 'date'],
            'remark' => ['nullable', 'string'],
        ]);
        $startTime = Carbon::parse($payload['start_time'] ?? now())->seconds(0);
        $equipment = Equipment::query()->whereIn('id', $payload['equipment_ids'])->get()->keyBy('id');
        $samples = Sample::query()->whereIn('id', $payload['sample_ids'])->get()->keyBy('id');
        $records = DB::transaction(function () use ($request, $payload, $equipment, $samples, $startTime): array {
            $created = [];

            foreach ($payload['equipment_ids'] as $equipmentId) {
                $targetEquipment = $equipment->get($equipmentId);

                foreach ($payload['sample_ids'] as $sampleId) {
                    $sample = $samples->get($sampleId);
                    $created[] = EquipmentUsageRecord::query()->create([
                        'equipment_id' => $targetEquipment->id,
                        'sample_id' => $sample->id,
                        'equipment_no' => $targetEquipment->equipment_no,
                        'equipment_name' => $targetEquipment->name,
                        'sample_no' => $sample->sample_no,
                        'sample_name' => $sample->sample_name,
                        'sample_model' => $sample->model,
                        'start_time' => $startTime,
                        'operator_id' => $request->user()?->id,
                        'operator_name' => $request->user()?->name,
                        'remark' => $payload['remark'] ?? null,
                    ]);
                }
            }

            return $created;
        });

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_usage_records.start',
            module: self::RESOURCE,
            after: [
                'equipment_ids' => $payload['equipment_ids'],
                'sample_ids' => $payload['sample_ids'],
                'created_count' => count($records),
            ],
        );

        return response()->json([
            'data' => collect($records)->map(fn (EquipmentUsageRecord $record): array => $this->serializeRecord($record))->values(),
            'meta' => ['created_count' => count($records)],
        ], 201);
    }

    public function update(Request $request, EquipmentUsageRecord $equipmentUsageRecord, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_usage_records.update', self::RESOURCE, $equipmentUsageRecord);

        $payload = $request->validate([
            'start_time' => ['sometimes', 'date'],
            'end_time' => ['nullable', 'date'],
            'remark' => ['nullable', 'string'],
        ]);
        $before = $this->serializeRecord($equipmentUsageRecord);
        $equipmentUsageRecord->update($payload);
        $equipmentUsageRecord = $equipmentUsageRecord->fresh();

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_usage_records.update',
            module: self::RESOURCE,
            subject: $equipmentUsageRecord,
            before: $before,
            after: $this->serializeRecord($equipmentUsageRecord),
        );

        return response()->json(['data' => $this->serializeRecord($equipmentUsageRecord)]);
    }

    public function end(Request $request, EquipmentUsageRecord $equipmentUsageRecord, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_usage_records.update', self::RESOURCE, $equipmentUsageRecord);

        if ($equipmentUsageRecord->end_time !== null) {
            throw ValidationException::withMessages(['end_time' => ['equipment_usage_already_finished']]);
        }

        $payload = $request->validate(['end_time' => ['nullable', 'date']]);
        $before = $this->serializeRecord($equipmentUsageRecord);
        $equipmentUsageRecord->update(['end_time' => Carbon::parse($payload['end_time'] ?? now())->seconds(0)]);
        $equipmentUsageRecord = $equipmentUsageRecord->fresh();

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_usage_records.end',
            module: self::RESOURCE,
            subject: $equipmentUsageRecord,
            before: $before,
            after: $this->serializeRecord($equipmentUsageRecord),
        );

        return response()->json(['data' => $this->serializeRecord($equipmentUsageRecord)]);
    }

    public function batchEnd(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_usage_records.update', self::RESOURCE);

        $payload = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:equipment_usage_records,id'],
            'end_time' => ['nullable', 'date'],
        ]);
        $endTime = Carbon::parse($payload['end_time'] ?? now())->seconds(0);
        $updated = EquipmentUsageRecord::query()
            ->whereIn('id', $payload['ids'])
            ->whereNull('end_time')
            ->update(['end_time' => $endTime, 'updated_at' => now()]);

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_usage_records.batch_end',
            module: self::RESOURCE,
            after: ['ids' => $payload['ids'], 'updated_count' => $updated],
        );

        return response()->json(['data' => ['updated_count' => $updated]]);
    }

    public function destroy(Request $request, EquipmentUsageRecord $equipmentUsageRecord, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_usage_records.delete', self::RESOURCE, $equipmentUsageRecord);

        $before = $this->serializeRecord($equipmentUsageRecord);
        $equipmentUsageRecord->delete();

        $auditLogger->record(
            actor: $request->user(),
            action: 'equipment_usage_records.delete',
            module: self::RESOURCE,
            before: $before,
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function filteredQuery(Request $request): Builder
    {
        return EquipmentUsageRecord::query()
            ->when($request->filled('equipment'), function (Builder $query) use ($request): void {
                $search = $request->string('equipment')->toString();
                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('equipment_no', 'like', "%{$search}%")
                    ->orWhere('equipment_name', 'like', "%{$search}%"));
            })
            ->when($request->filled('sample'), function (Builder $query) use ($request): void {
                $search = $request->string('sample')->toString();
                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('sample_no', 'like', "%{$search}%")
                    ->orWhere('sample_name', 'like', "%{$search}%")
                    ->orWhere('sample_model', 'like', "%{$search}%"));
            })
            ->when($request->string('status')->toString() === 'using', fn (Builder $query): Builder => $query->whereNull('end_time'))
            ->when($request->string('status')->toString() === 'finished', fn (Builder $query): Builder => $query->whereNotNull('end_time'));
    }

    private function serializeRecord(EquipmentUsageRecord $record): array
    {
        return [
            'id' => $record->id,
            'equipment_id' => $record->equipment_id,
            'sample_id' => $record->sample_id,
            'equipment_no' => $record->equipment_no,
            'equipment_name' => $record->equipment_name,
            'sample_no' => $record->sample_no,
            'sample_name' => $record->sample_name,
            'sample_model' => $record->sample_model,
            'start_time' => $record->start_time?->format('Y-m-d H:i:s'),
            'end_time' => $record->end_time?->format('Y-m-d H:i:s'),
            'status' => $record->end_time === null ? 'using' : 'finished',
            'operator_id' => $record->operator_id,
            'operator_name' => $record->operator_name,
            'remark' => $record->remark,
        ];
    }
}

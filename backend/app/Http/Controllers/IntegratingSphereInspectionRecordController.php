<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentSystem;
use App\Models\IntegratingSphereInspectionEquipment;
use App\Models\IntegratingSphereInspectionRecord;
use App\Models\Sample;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PermissionAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IntegratingSphereInspectionRecordController extends Controller
{
    private const RESOURCE = 'integrating_sphere_inspection_records';

    /**
     * Measurement columns and the scale the form promises for each of them. The
     * same map drives validation and keeps the rules in step with the migration.
     */
    private const MEASUREMENT_SCALES = [
        'chromaticity_x' => 4,
        'chromaticity_y' => 4,
        'dominant_wavelength' => 1,
        'peak_wavelength' => 1,
        'color_temperature' => 0,
        'color_rendering_index' => 1,
        'luminous_flux' => 1,
        'voltage' => 1,
        'current' => 4,
        'power' => 4,
        'power_factor' => 4,
        'frequency' => 0,
    ];

    /**
     * Bounds mirror the column precision from the migration so an out-of-range
     * entry fails validation instead of overflowing on a strict-mode database.
     */
    private const INTEGER_BOUNDS = [
        'color_temperature' => [0, 1000000],
        'frequency' => [0, 1000000],
    ];

    private const DECIMAL_BOUNDS = [
        'chromaticity_x' => [0, '99.9999'],
        'chromaticity_y' => [0, '99.9999'],
        'dominant_wavelength' => [0, '999999.9'],
        'peak_wavelength' => [0, '999999.9'],
        'color_rendering_index' => ['-9999.9', '9999.9'],
        'luminous_flux' => [0, '99999999999.9'],
        'voltage' => [0, '99999999.9'],
        'current' => [0, '99999999.9999'],
        'power' => [0, '99999999.9999'],
        'power_factor' => [0, '99.9999'],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);

        $records = $this->filteredQuery($request)
            ->with('equipment')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $records->getCollection()
                ->map(fn (IntegratingSphereInspectionRecord $record): array => $this->serializeRecord($record))
                ->values(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    /**
     * The global used-equipment ledger: every device association across every record,
     * flattened for a searchable history.
     *
     * The rows are the existing child snapshots joined to their parent; nothing is
     * duplicated onto the child table, so the equipment fields keep coming from the
     * immutable child snapshot and the date and operator from the immutable parent.
     */
    public function equipmentLedger(Request $request): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);

        $rows = $this->equipmentLedgerQuery($request)
            ->with('record')
            ->select('integrating_sphere_inspection_equipment.*')
            ->orderByDesc('parent.recorded_at')
            ->orderByDesc('integrating_sphere_inspection_equipment.inspection_record_id')
            ->orderBy('integrating_sphere_inspection_equipment.id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $rows->getCollection()
                ->map(fn (IntegratingSphereInspectionEquipment $row): array => $this->serializeEquipmentLedgerRow($row))
                ->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(Request $request, IntegratingSphereInspectionRecord $inspectionRecord): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE, $inspectionRecord);

        return response()->json(['data' => $this->serializeRecord($inspectionRecord->load('equipment'))]);
    }

    /**
     * Equipment, sample and system codes arrive from the camera scanner or from
     * manual typing, so the lookup is open to anyone who may create or edit a record
     * and deliberately does not require read access to the ledgers themselves.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorizeLookupPermission($request);

        $payload = $request->validate([
            'type' => ['required', 'in:equipment,sample,system'],
            'code' => ['required', 'string', 'max:255'],
        ]);

        if ($payload['type'] === 'equipment') {
            $equipment = Equipment::query()->where('equipment_no', $payload['code'])->firstOrFail();

            return response()->json(['data' => $this->serializeEquipmentOption($equipment)]);
        }

        // A system code is an independent operator input, never inferred from the
        // devices. Only an active system can answer a fresh scan; a disabled one stays
        // valid history on the records that already carry its snapshot.
        if ($payload['type'] === 'system') {
            $system = EquipmentSystem::query()->where('code', $payload['code'])->where('status', 'active')->firstOrFail();

            return response()->json(['data' => $this->serializeSystemOption($system)]);
        }

        $sample = Sample::query()->where('sample_no', $payload['code'])->firstOrFail();

        return response()->json(['data' => $this->serializeSampleOption($sample)]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.create', self::RESOURCE);

        $payload = $request->validate($this->storeRules());
        $sample = Sample::query()->findOrFail($payload['sample_id']);
        $system = $this->activeSystemFor((int) $payload['equipment_system_id']);
        $equipment = $this->equipmentFor($payload['equipment_ids']);

        $record = DB::transaction(function () use ($request, $payload, $sample, $system, $equipment): IntegratingSphereInspectionRecord {
            $record = IntegratingSphereInspectionRecord::query()->create([
                'sample_id' => $sample->id,
                'sample_no' => $sample->sample_no,
                'equipment_system_id' => $system->id,
                'system_code' => $system->code,
                ...$this->measurementValues($payload),
                'remark' => $this->normalizedRemark($payload),
                'recorded_at' => $this->recordedAt($payload),
                'operator_id' => $request->user()?->id,
                'operator_name' => $request->user()?->name,
            ]);

            $this->syncEquipmentSnapshots($record, $payload['equipment_ids'], $equipment);

            return $record;
        });

        $record = $record->fresh(['equipment']);

        $auditLogger->record(
            actor: $request->user(),
            action: self::RESOURCE.'.create',
            module: self::RESOURCE,
            subject: $record,
            after: $this->serializeRecord($record),
        );

        return response()->json(['data' => $this->serializeRecord($record)], 201);
    }

    public function update(Request $request, IntegratingSphereInspectionRecord $inspectionRecord, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.update', self::RESOURCE, $inspectionRecord);

        $payload = $request->validate($this->updateRules());
        $addedIds = $payload['equipment_ids'] ?? [];
        $retainedIds = $this->retainedChildIds($inspectionRecord, $payload, $addedIds);
        // A re-declared sample re-snapshots from the ledger; an omitted one keeps the
        // snapshot already on the record, which is the only evidence left once the
        // ledger row is gone.
        $sample = isset($payload['sample_id']) ? Sample::query()->findOrFail($payload['sample_id']) : null;
        // Same retained/selected split as the sample: an omitted system keeps the code
        // snapshot the record already holds, which is the only evidence left once the
        // system has been renamed, disabled or deleted.
        $system = isset($payload['equipment_system_id']) ? $this->activeSystemFor((int) $payload['equipment_system_id']) : null;
        $equipment = $this->equipmentFor($addedIds);
        $before = $this->serializeRecord($inspectionRecord->load('equipment'));

        DB::transaction(function () use ($inspectionRecord, $payload, $sample, $system, $equipment, $addedIds, $retainedIds): void {
            $inspectionRecord->update([
                'sample_id' => $sample?->id ?? $inspectionRecord->sample_id,
                'sample_no' => $sample?->sample_no ?? $inspectionRecord->sample_no,
                'equipment_system_id' => $system?->id ?? $inspectionRecord->equipment_system_id,
                'system_code' => $system?->code ?? $inspectionRecord->system_code,
                ...$this->measurementValues($payload),
                'remark' => $this->normalizedRemark($payload),
                'recorded_at' => $this->recordedAt($payload, $inspectionRecord->recorded_at),
            ]);

            // Retained children are never rewritten, so a snapshot whose ledger row was
            // edited or deleted keeps the values the measurement was actually taken with.
            $inspectionRecord->equipment()->whereNotIn('id', $retainedIds)->delete();
            $this->syncEquipmentSnapshots($inspectionRecord, $addedIds, $equipment);
        });

        $record = $inspectionRecord->fresh(['equipment']);

        $auditLogger->record(
            actor: $request->user(),
            action: self::RESOURCE.'.update',
            module: self::RESOURCE,
            subject: $record,
            before: $before,
            after: $this->serializeRecord($record),
        );

        return response()->json(['data' => $this->serializeRecord($record)]);
    }

    public function destroy(Request $request, IntegratingSphereInspectionRecord $inspectionRecord, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.delete', self::RESOURCE, $inspectionRecord);

        $before = $this->serializeRecord($inspectionRecord->load('equipment'));
        $inspectionRecord->delete();

        $auditLogger->record(
            actor: $request->user(),
            action: self::RESOURCE.'.delete',
            module: self::RESOURCE,
            before: $before,
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function authorizeLookupPermission(Request $request): void
    {
        $permissionAccess = app(PermissionAccess::class);

        if ($permissionAccess->userCan($request->user(), self::RESOURCE.'.create')
            || $permissionAccess->userCan($request->user(), self::RESOURCE.'.update')) {
            return;
        }

        $this->authorizePermission($request, self::RESOURCE.'.create', self::RESOURCE);
    }

    /**
     * Works out which existing child snapshots survive the edit.
     *
     * Omitting `retained_equipment_ids` keeps every snapshot the record already has,
     * so a payload that only corrects a measurement can never destroy the device
     * history. When the field is present it is authoritative, which lets an operator
     * deliberately drop a snapshot — but only one that belongs to this record, so a
     * child id from a different record can never be grafted on.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $addedIds
     * @return array<int, int>
     */
    private function retainedChildIds(IntegratingSphereInspectionRecord $record, array $payload, array $addedIds): array
    {
        $children = $record->equipment()->get();

        if (! array_key_exists('retained_equipment_ids', $payload)) {
            $retained = $children->pluck('id')->all();
        } else {
            $retained = array_values(array_map('intval', $payload['retained_equipment_ids']));
            $ownIds = $children->pluck('id')->all();

            if (array_diff($retained, $ownIds) !== []) {
                throw ValidationException::withMessages([
                    'retained_equipment_ids' => ['integrating_sphere_retained_equipment_invalid'],
                ]);
            }
        }

        if ($retained === [] && $addedIds === []) {
            throw ValidationException::withMessages([
                'equipment_ids' => ['integrating_sphere_equipment_required'],
            ]);
        }

        // Re-snapshotting a device that is already retained would duplicate the pairing
        // the unique index forbids, and would silently overwrite the retained values.
        $retainedEquipmentIds = $children
            ->whereIn('id', $retained)
            ->pluck('equipment_id')
            ->filter()
            ->all();

        if (array_intersect($retainedEquipmentIds, $addedIds) !== []) {
            throw ValidationException::withMessages([
                'equipment_ids' => ['integrating_sphere_equipment_already_retained'],
            ]);
        }

        return $retained;
    }

    /**
     * Resolves an explicitly scanned or typed system.
     *
     * The `exists` rule already rejects an unknown id, so a failure here means the row
     * is not selectable: it was disabled, or a concurrent request deleted it. Either
     * way a new selection must point at a live active system, while the records that
     * already reference it keep their snapshot untouched.
     */
    private function activeSystemFor(int $systemId): EquipmentSystem
    {
        $system = EquipmentSystem::query()->whereKey($systemId)->first();

        if ($system === null || $system->status !== 'active') {
            throw ValidationException::withMessages([
                'equipment_system_id' => ['integrating_sphere_system_inactive'],
            ]);
        }

        return $system;
    }

    /**
     * Resolves the selected devices up front. The `exists` rule already rejects an
     * unknown id, so this only fires when a concurrent request deletes the ledger row
     * in between; reporting it beats silently writing a snapshot without the device.
     *
     * @param  array<int, int>  $equipmentIds
     * @return Collection<int, Equipment>
     */
    private function equipmentFor(array $equipmentIds): Collection
    {
        $equipment = Equipment::query()->whereIn('id', $equipmentIds)->get()->keyBy('id');

        if ($equipment->count() !== count($equipmentIds)) {
            throw ValidationException::withMessages(['equipment_ids' => ['integrating_sphere_equipment_missing']]);
        }

        return $equipment;
    }

    /**
     * @param  array<int, int>  $equipmentIds
     * @param  Collection<int, Equipment>  $equipment
     */
    private function syncEquipmentSnapshots(IntegratingSphereInspectionRecord $record, array $equipmentIds, Collection $equipment): void
    {
        foreach ($equipmentIds as $equipmentId) {
            $device = $equipment->get($equipmentId);

            $record->equipment()->create([
                'equipment_id' => $device->id,
                'equipment_no' => $device->equipment_no,
                'equipment_name' => $device->name,
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'serial_no' => $device->serial_no,
                'next_calibration_date' => $device->next_calibration_date,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function measurementValues(array $payload): array
    {
        $values = [];

        foreach (array_keys(self::MEASUREMENT_SCALES) as $field) {
            $values[$field] = $payload[$field];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalizedRemark(array $payload): ?string
    {
        $remark = $payload['remark'] ?? null;

        if ($remark === null) {
            return null;
        }

        $remark = trim((string) $remark);

        return $remark === '' ? null : $remark;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordedAt(array $payload, ?Carbon $fallback = null): Carbon
    {
        if (($payload['recorded_at'] ?? null) !== null) {
            return Carbon::parse($payload['recorded_at'])->microseconds(0);
        }

        return $fallback ?? Carbon::now()->microseconds(0);
    }

    private function filteredQuery(Request $request): Builder
    {
        return IntegratingSphereInspectionRecord::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('sample_no', 'like', "%{$search}%")
                    ->orWhere('system_code', 'like', "%{$search}%")
                    ->orWhereHas('equipment', fn (Builder $equipment): Builder => $equipment
                        ->where('equipment_no', 'like', "%{$search}%")
                        ->orWhere('equipment_name', 'like', "%{$search}%")));
            })
            ->when($request->filled('date_from'), fn (Builder $query): Builder => $query->where('recorded_at', '>=', $request->string('date_from')->toString().' 00:00:00'))
            ->when($request->filled('date_to'), fn (Builder $query): Builder => $query->where('recorded_at', '<=', $request->string('date_to')->toString().' 23:59:59'));
    }

    private function equipmentLedgerQuery(Request $request): Builder
    {
        $table = 'integrating_sphere_inspection_equipment';

        return IntegratingSphereInspectionEquipment::query()
            ->join('integrating_sphere_inspection_records as parent', 'parent.id', '=', $table.'.inspection_record_id')
            ->when($request->filled('search'), function (Builder $query) use ($request, $table): void {
                $search = $request->string('search')->toString();
                $query->where(function (Builder $builder) use ($search, $table): void {
                    $builder
                        ->where($table.'.equipment_no', 'like', "%{$search}%")
                        ->orWhere($table.'.equipment_name', 'like', "%{$search}%")
                        ->orWhere($table.'.manufacturer', 'like', "%{$search}%")
                        ->orWhere($table.'.model', 'like', "%{$search}%")
                        ->orWhere($table.'.serial_no', 'like', "%{$search}%");

                    // All three ids the table shows are searchable, but only as whole
                    // numbers: a substring match on an id would make "1" pull in every
                    // record from 10 up.
                    if (ctype_digit($search)) {
                        $builder
                            ->orWhere($table.'.id', (int) $search)
                            ->orWhere($table.'.inspection_record_id', (int) $search)
                            ->orWhere($table.'.equipment_id', (int) $search);
                    }
                });
            })
            ->when(
                $request->filled('inspection_record_id'),
                fn (Builder $query): Builder => $query->where($table.'.inspection_record_id', $request->integer('inspection_record_id')),
            )
            ->when(
                $request->filled('equipment_id'),
                fn (Builder $query): Builder => $query->where($table.'.equipment_id', $request->integer('equipment_id')),
            )
            ->when(
                $request->filled('date_from'),
                fn (Builder $query): Builder => $query->where('parent.recorded_at', '>=', $request->string('date_from')->toString().' 00:00:00'),
            )
            ->when(
                $request->filled('date_to'),
                fn (Builder $query): Builder => $query->where('parent.recorded_at', '<=', $request->string('date_to')->toString().' 23:59:59'),
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEquipmentLedgerRow(IntegratingSphereInspectionEquipment $row): array
    {
        return [
            'id' => $row->id,
            'inspection_record_id' => $row->inspection_record_id,
            'equipment_id' => $row->equipment_id,
            'equipment_no' => $row->equipment_no,
            'equipment_name' => $row->equipment_name,
            'manufacturer' => $row->manufacturer,
            'model' => $row->model,
            'serial_no' => $row->serial_no,
            'next_calibration_date' => $row->next_calibration_date?->toDateString(),
            'recorded_at' => $row->record?->recorded_at?->format('Y-m-d H:i:s'),
            'operator_name' => $row->record?->operator_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeRules(): array
    {
        return [
            'sample_id' => ['required', 'integer', 'exists:samples,id'],
            'equipment_system_id' => ['required', 'integer', 'exists:equipment_systems,id'],
            'equipment_ids' => ['required', 'array', 'min:1'],
            'equipment_ids.*' => ['integer', 'distinct', 'exists:equipment,id'],
            ...$this->sharedRules(),
        ];
    }

    /**
     * An edit re-declares only what it sends. Every ledger reference is optional so a
     * record whose sample, system or devices were removed from the ledger stays
     * editable without the API having to resurrect the deleted rows.
     *
     * @return array<string, mixed>
     */
    private function updateRules(): array
    {
        return [
            'sample_id' => ['nullable', 'integer', 'exists:samples,id'],
            'equipment_system_id' => ['nullable', 'integer', 'exists:equipment_systems,id'],
            'equipment_ids' => ['sometimes', 'array'],
            'equipment_ids.*' => ['integer', 'distinct', 'exists:equipment,id'],
            'retained_equipment_ids' => ['sometimes', 'array'],
            'retained_equipment_ids.*' => ['integer', 'distinct'],
            ...$this->sharedRules(),
        ];
    }

    /**
     * Decimal measurements are accepted as canonical strings only.
     *
     * A JSON number would reach PHP as a float, and no amount of care afterwards can
     * recover a scale that binary floating point has already rounded away. The regex
     * pins the accepted spelling to plain fixed-point digits, which rules out the
     * notations whose real scale is not what it looks like — `1e-5` counts as zero
     * decimals to the `decimal` rule, and `1.` or `.5` are numeric to PHP but are not
     * a form this API should be storing.
     *
     * @return array<string, mixed>
     */
    private function sharedRules(): array
    {
        $rules = [
            'recorded_at' => ['nullable', 'date'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (self::MEASUREMENT_SCALES as $field => $scale) {
            $rules[$field] = $scale === 0
                ? ['bail', 'required', 'integer', 'between:'.self::INTEGER_BOUNDS[$field][0].','.self::INTEGER_BOUNDS[$field][1]]
                : [
                    'bail',
                    'required',
                    'string',
                    'numeric',
                    "decimal:0,{$scale}",
                    'regex:/^-?[0-9]+(\.[0-9]{1,'.$scale.'})?$/',
                    'between:'.self::DECIMAL_BOUNDS[$field][0].','.self::DECIMAL_BOUNDS[$field][1],
                ];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEquipmentOption(Equipment $equipment): array
    {
        return [
            'id' => $equipment->id,
            'equipment_no' => $equipment->equipment_no,
            'equipment_name' => $equipment->name,
            'manufacturer' => $equipment->manufacturer,
            'model' => $equipment->model,
            'serial_no' => $equipment->serial_no,
            'next_calibration_date' => $equipment->next_calibration_date?->toDateString(),
            'status' => $equipment->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSystemOption(EquipmentSystem $system): array
    {
        return [
            'id' => $system->id,
            'code' => $system->code,
            'name' => $system->name,
            'status' => $system->status,
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

    /**
     * @return array<string, mixed>
     */
    private function serializeRecord(IntegratingSphereInspectionRecord $record): array
    {
        return [
            'id' => $record->id,
            'sample_id' => $record->sample_id,
            'sample_no' => $record->sample_no,
            'equipment_system_id' => $record->equipment_system_id,
            'system_code' => $record->system_code,
            'chromaticity_x' => $record->chromaticity_x,
            'chromaticity_y' => $record->chromaticity_y,
            'dominant_wavelength' => $record->dominant_wavelength,
            'peak_wavelength' => $record->peak_wavelength,
            'color_temperature' => $record->color_temperature,
            'color_rendering_index' => $record->color_rendering_index,
            'luminous_flux' => $record->luminous_flux,
            'voltage' => $record->voltage,
            'current' => $record->current,
            'power' => $record->power,
            'power_factor' => $record->power_factor,
            'frequency' => $record->frequency,
            'remark' => $record->remark,
            'recorded_at' => $record->recorded_at?->format('Y-m-d H:i:s'),
            'operator_id' => $record->operator_id,
            'operator_name' => $record->operator_name,
            'created_at' => $record->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $record->updated_at?->format('Y-m-d H:i:s'),
            'equipment' => $record->equipment
                ->map(fn (IntegratingSphereInspectionEquipment $device): array => [
                    'id' => $device->id,
                    'equipment_id' => $device->equipment_id,
                    'equipment_no' => $device->equipment_no,
                    'equipment_name' => $device->equipment_name,
                    'manufacturer' => $device->manufacturer,
                    'model' => $device->model,
                    'serial_no' => $device->serial_no,
                    'next_calibration_date' => $device->next_calibration_date?->toDateString(),
                ])
                ->values(),
        ];
    }
}

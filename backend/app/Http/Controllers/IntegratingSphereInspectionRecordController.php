<?php

namespace App\Http\Controllers;

use App\Models\IntegratingSphereInspectionEquipment;
use App\Models\IntegratingSphereInspectionRecord;
use App\Models\Sample;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PermissionAccess;
use App\Services\Inspection\InspectionEquipmentLedger;
use App\Services\Inspection\InspectionEquipmentSnapshots;
use App\Services\Inspection\InspectionSubjectLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IntegratingSphereInspectionRecordController extends Controller
{
    private const RESOURCE = 'integrating_sphere_inspection_records';

    /** Namespaces the validation message keys this workflow returns. */
    private const MESSAGE_PREFIX = 'integrating_sphere';

    private const EQUIPMENT_TABLE = 'integrating_sphere_inspection_equipment';

    private const RECORDS_TABLE = 'integrating_sphere_inspection_records';

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
        'voltage' => 2,
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
        'voltage' => [0, '99999999.99'],
        'current' => [0, '99999999.9999'],
        'power' => [0, '99999999.9999'],
        'power_factor' => [0, '99.9999'],
    ];

    public function __construct(
        private readonly InspectionSubjectLookup $subjects,
        private readonly InspectionEquipmentSnapshots $snapshots,
        private readonly InspectionEquipmentLedger $ledger,
    ) {}

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

        $rows = $this->ledger
            ->applyOrdering($this->equipmentLedgerQuery($request)->with('record'), self::EQUIPMENT_TABLE)
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $rows->getCollection()
                ->map(fn (IntegratingSphereInspectionEquipment $row): array => $this->ledger->serializeRow($row))
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
            return response()->json([
                'data' => $this->subjects->serializeEquipmentOption($this->subjects->equipmentByNo($payload['code'])),
            ]);
        }

        if ($payload['type'] === 'system') {
            return response()->json([
                'data' => $this->subjects->serializeSystemOption($this->subjects->activeSystemByCode($payload['code'])),
            ]);
        }

        return response()->json([
            'data' => $this->subjects->serializeSampleOption($this->subjects->sampleByNo($payload['code'])),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.create', self::RESOURCE);

        $payload = $request->validate($this->storeRules());
        $sample = Sample::query()->findOrFail($payload['sample_id']);
        $system = $this->subjects->activeSystemFor((int) $payload['equipment_system_id'], self::MESSAGE_PREFIX);
        $equipment = $this->subjects->equipmentFor($payload['equipment_ids'], self::MESSAGE_PREFIX);

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

            $this->snapshots->sync($record, $payload['equipment_ids'], $equipment);

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
        $retainedIds = $this->snapshots->retainedChildIds($inspectionRecord, $payload, $addedIds, self::MESSAGE_PREFIX);
        // A re-declared sample re-snapshots from the ledger; an omitted one keeps the
        // snapshot already on the record, which is the only evidence left once the
        // ledger row is gone.
        $sample = isset($payload['sample_id']) ? Sample::query()->findOrFail($payload['sample_id']) : null;
        // Same retained/selected split as the sample: an omitted system keeps the code
        // snapshot the record already holds, which is the only evidence left once the
        // system has been renamed, disabled or deleted.
        $system = isset($payload['equipment_system_id'])
            ? $this->subjects->activeSystemFor((int) $payload['equipment_system_id'], self::MESSAGE_PREFIX)
            : null;
        $equipment = $this->subjects->equipmentFor($addedIds, self::MESSAGE_PREFIX);
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
            $this->snapshots->sync($inspectionRecord, $addedIds, $equipment);
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
        return $this->ledger->applyFilters(
            IntegratingSphereInspectionEquipment::query(),
            $request,
            self::EQUIPMENT_TABLE,
            self::RECORDS_TABLE,
        );
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
                ->map(fn (IntegratingSphereInspectionEquipment $device): array => $this->snapshots->serialize($device))
                ->values(),
        ];
    }
}

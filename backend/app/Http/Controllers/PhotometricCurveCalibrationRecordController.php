<?php

namespace App\Http\Controllers;

use App\Models\PhotometricCurveCalibrationEquipment;
use App\Models\PhotometricCurveCalibrationRecord;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PermissionAccess;
use App\Services\Inspection\CalibrationStandardSnapshots;
use App\Services\Inspection\InspectionEquipmentLedger;
use App\Services\Inspection\InspectionEquipmentSnapshots;
use App\Services\Inspection\InspectionMediaLibrary;
use App\Services\Inspection\InspectionSubjectLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PhotometricCurveCalibrationRecordController extends Controller
{
    private const RESOURCE = 'photometric_curve_calibration_records';

    /** Namespaces the validation message keys this workflow returns. */
    private const MESSAGE_PREFIX = 'photometric_curve_calibration';

    private const EQUIPMENT_TABLE = 'photometric_curve_calibration_equipment';

    private const RECORDS_TABLE = 'photometric_curve_calibration_records';

    /**
     * The probe the goniophotometer is calibrated against. It is a fixed pair of
     * codes with no administrator-managed catalog behind it, so the stable code is
     * stored as a plain string and the Chinese labels stay a presentation concern.
     */
    private const PROBES = ['near_field', 'far_field'];

    /**
     * Measurement columns and the scale the form promises for each of them. The
     * same map drives validation and keeps the rules in step with the migration.
     */
    private const MEASUREMENT_SCALES = [
        'test_distance' => 4,
        'calibration_coefficient' => 4,
        'peak_luminous_intensity' => 1,
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
        'frequency' => [0, 1000000],
    ];

    private const DECIMAL_BOUNDS = [
        'test_distance' => [0, '99999999.9999'],
        'calibration_coefficient' => [0, '99999999.9999'],
        'peak_luminous_intensity' => [0, '99999999999.9'],
        'luminous_flux' => [0, '99999999999.9'],
        'voltage' => [0, '99999999.99'],
        'current' => [0, '99999999.9999'],
        'power' => [0, '99999999.9999'],
    ];

    /**
     * Array fields that a multipart body cannot express when they are empty.
     */
    private const NORMALIZED_ARRAY_FIELDS = ['retained_equipment_ids', 'retained_media_ids'];

    public function __construct(
        private readonly InspectionSubjectLookup $subjects,
        private readonly InspectionEquipmentSnapshots $snapshots,
        private readonly InspectionEquipmentLedger $ledger,
        private readonly InspectionMediaLibrary $mediaLibrary,
        private readonly CalibrationStandardSnapshots $standardSnapshots,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);

        $records = $this->filteredQuery($request)
            ->with(['equipment', 'media'])
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $records->getCollection()
                ->map(fn (PhotometricCurveCalibrationRecord $record): array => $this->serializeRecord($record))
                ->values(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function equipmentLedger(Request $request): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);

        $rows = $this->ledger
            ->applyOrdering($this->equipmentLedgerQuery($request)->with('record'), self::EQUIPMENT_TABLE, 'calibration_record_id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $rows->getCollection()
                ->map(fn (PhotometricCurveCalibrationEquipment $row): array => $this->ledger->serializeRow($row))
                ->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(Request $request, PhotometricCurveCalibrationRecord $record): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE, $record);

        return response()->json(['data' => $this->serializeRecord($record->load(['equipment', 'media']))]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorizeLookupPermission($request);

        $payload = $request->validate([
            'type' => ['required', 'in:equipment,standard,system'],
            'code' => ['required', 'string', 'max:255'],
        ]);

        if ($payload['type'] === 'equipment' || $payload['type'] === 'standard') {
            return response()->json([
                'data' => $this->subjects->serializeEquipmentOption($this->subjects->equipmentByNo($payload['code'])),
            ]);
        }

        return response()->json([
            'data' => $this->subjects->serializeSystemOption($this->subjects->activeSystemByCode($payload['code'])),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.create', self::RESOURCE);

        $this->normalizeArrayFields($request);
        $payload = $request->validate($this->storeRules());

        $standard = $this->standardSnapshots->requiredFor($payload);
        $system = $this->subjects->activeSystemFor((int) $payload['equipment_system_id'], self::MESSAGE_PREFIX);
        $equipment = $this->subjects->equipmentFor($payload['equipment_ids'], self::MESSAGE_PREFIX);

        $uploads = $this->mediaLibrary->validatedUploads($request, [], self::MESSAGE_PREFIX);
        $written = [];

        try {
            $record = DB::transaction(function () use ($request, $payload, $standard, $system, $equipment, $uploads, &$written): PhotometricCurveCalibrationRecord {
                $record = PhotometricCurveCalibrationRecord::query()->create([
                    ...$this->standardSnapshots->columns($standard),
                    'equipment_system_id' => $system->id,
                    'system_code' => $system->code,
                    'system_name' => $system->name,
                    'probe' => $payload['probe'],
                    ...$this->measurementValues($payload),
                    'remark' => $this->normalizedRemark($payload),
                    'recorded_at' => Carbon::now()->microseconds(0),
                    'operator_id' => $request->user()?->id,
                    'operator_name' => $request->user()?->name,
                ]);

                $this->snapshots->sync($record, $payload['equipment_ids'], $equipment);
                $this->mediaLibrary->attach($record, $uploads, [], $written);

                return $record;
            });
        } catch (Throwable $exception) {
            $this->mediaLibrary->discardFiles($written);

            throw $exception;
        }

        $record = $record->fresh(['equipment', 'media']);

        $auditLogger->record(
            actor: $request->user(),
            action: self::RESOURCE.'.create',
            module: self::RESOURCE,
            subject: $record,
            after: $this->serializeRecord($record),
        );

        return response()->json(['data' => $this->serializeRecord($record)], 201);
    }

    public function update(Request $request, PhotometricCurveCalibrationRecord $record, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.update', self::RESOURCE, $record);

        $this->normalizeArrayFields($request);
        $payload = $request->validate($this->updateRules());

        $addedIds = $payload['equipment_ids'] ?? [];
        $retainedIds = $this->snapshots->retainedChildIds($record, $payload, $addedIds, self::MESSAGE_PREFIX);

        $standard = $this->standardSnapshots->optionalFor($payload);

        $system = isset($payload['equipment_system_id'])
            ? $this->subjects->activeSystemFor((int) $payload['equipment_system_id'], self::MESSAGE_PREFIX)
            : null;

        $equipment = $this->subjects->equipmentFor($addedIds, self::MESSAGE_PREFIX);
        $record->load('media');
        $existingMediaIds = $this->mediaLibrary->existingMediaIds($record);
        $retainedMedia = $this->mediaLibrary->retainedMedia($record, $payload, self::MESSAGE_PREFIX);
        $uploads = $this->mediaLibrary->validatedUploads($request, $retainedMedia, self::MESSAGE_PREFIX);
        $before = $this->serializeRecord($record->load(['equipment', 'media']));
        $written = [];

        try {
            DB::transaction(function () use ($record, $payload, $standard, $system, $equipment, $addedIds, $retainedIds, $uploads, $existingMediaIds, &$written): void {
                $systemData = $system !== null ? [
                    'equipment_system_id' => $system->id,
                    'system_code' => $system->code,
                    'system_name' => $system->name,
                ] : [];

                $record->update([
                    ...$this->standardSnapshots->columns($standard),
                    ...$systemData,
                    'probe' => $payload['probe'],
                    ...$this->measurementValues($payload),
                    'remark' => $this->normalizedRemark($payload),
                ]);

                $record->equipment()->whereNotIn('id', $retainedIds)->delete();
                $this->snapshots->sync($record, $addedIds, $equipment);
                $this->mediaLibrary->attach($record, $uploads, $existingMediaIds, $written);
            });
        } catch (Throwable $exception) {
            $this->mediaLibrary->discardFiles($written);

            throw $exception;
        }

        $this->mediaLibrary->deleteRemoved($record, $retainedMedia, $written);

        $record = $record->fresh(['equipment', 'media']);

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

    public function destroy(Request $request, PhotometricCurveCalibrationRecord $record, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.delete', self::RESOURCE, $record);

        $before = $this->serializeRecord($record->load(['equipment', 'media']));
        $record->delete();

        $auditLogger->record(
            actor: $request->user(),
            action: self::RESOURCE.'.delete',
            module: self::RESOURCE,
            before: $before,
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function viewMedia(Request $request, PhotometricCurveCalibrationRecord $record, Media $media): BinaryFileResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE, $record);

        $media = $this->mediaLibrary->ownedMedia($record, $media, PhotometricCurveCalibrationRecord::PHOTO_COLLECTION);

        return $this->mediaLibrary->inlineResponse($media);
    }

    public function downloadMedia(Request $request, PhotometricCurveCalibrationRecord $record, Media $media, AuditLogger $auditLogger): BinaryFileResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE, $record);

        $media = $this->mediaLibrary->ownedMedia($record, $media);

        $auditLogger->record(
            actor: $request->user(),
            action: self::RESOURCE.'.media.download',
            module: self::RESOURCE,
            subject: $record,
            after: $this->mediaLibrary->serialize($media),
        );

        return $this->mediaLibrary->downloadResponse($media);
    }

    /**
     * Scanning a code is part of writing a record, not part of browsing the ledgers,
     * so anyone allowed to create or update one may resolve a code without also
     * being granted broad equipment/system read access.
     */
    private function authorizeLookupPermission(Request $request): void
    {
        $permissionAccess = app(PermissionAccess::class);

        if ($permissionAccess->userCan($request->user(), self::RESOURCE.'.create')
            || $permissionAccess->userCan($request->user(), self::RESOURCE.'.update')) {
            return;
        }

        $this->authorizePermission($request, self::RESOURCE.'.create', self::RESOURCE);
    }

    private function normalizeArrayFields(Request $request): void
    {
        foreach (self::NORMALIZED_ARRAY_FIELDS as $field) {
            if (! $request->has($field) || is_array($request->input($field))) {
                continue;
            }

            $value = $request->input($field);
            $request->merge([$field => ($value === null || $value === '') ? [] : [$value]]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function measurementValues(array $payload): array
    {
        $values = [];

        foreach (self::MEASUREMENT_SCALES as $field => $scale) {
            $values[$field] = $scale === 0 ? (int) $payload[$field] : $payload[$field];
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

    private function filteredQuery(Request $request): Builder
    {
        return PhotometricCurveCalibrationRecord::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('standard_no', 'like', "%{$search}%")
                    ->orWhere('standard_name', 'like', "%{$search}%")
                    ->orWhere('system_code', 'like', "%{$search}%")
                    ->orWhere('system_name', 'like', "%{$search}%")
                    ->orWhereHas('equipment', fn (Builder $equipment): Builder => $equipment
                        ->where('equipment_no', 'like', "%{$search}%")
                        ->orWhere('equipment_name', 'like', "%{$search}%")));
            })
            ->when($request->filled('probe'), fn (Builder $query): Builder => $query->where('probe', $request->string('probe')->toString()))
            ->when($request->filled('date_from'), fn (Builder $query): Builder => $query->where('recorded_at', '>=', $request->string('date_from')->toString().' 00:00:00'))
            ->when($request->filled('date_to'), fn (Builder $query): Builder => $query->where('recorded_at', '<=', $request->string('date_to')->toString().' 23:59:59'));
    }

    private function equipmentLedgerQuery(Request $request): Builder
    {
        return $this->ledger->applyFilters(
            PhotometricCurveCalibrationEquipment::query(),
            $request,
            self::EQUIPMENT_TABLE,
            self::RECORDS_TABLE,
            'calibration_record_id'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storeRules(): array
    {
        return [
            'standard_equipment_id' => ['required', 'integer', 'exists:equipment,id'],
            'equipment_system_id' => ['required', 'integer', 'exists:equipment_systems,id'],
            'equipment_ids' => ['required', 'array', 'min:1'],
            'equipment_ids.*' => ['integer', 'distinct', 'exists:equipment,id'],
            ...$this->sharedRules(),
        ];
    }

    /**
     * An edit re-declares only what it sends. Every ledger reference is optional so a
     * record whose standard, system or devices were removed from the ledger stays
     * editable without the API having to resurrect the deleted rows.
     *
     * @return array<string, mixed>
     */
    private function updateRules(): array
    {
        return [
            'standard_equipment_id' => ['nullable', 'integer', 'exists:equipment,id'],
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
        // `recorded_at` is intentionally not a rule. It is a server-owned audit value:
        // omitting it from the writable set means a payload that carries one has it
        // stripped by validation rather than quietly honoured.
        $rules = [
            'remark' => ['nullable', 'string', 'max:2000'],
            'probe' => ['required', 'string', 'in:'.implode(',', self::PROBES)],
            // The power factor is a ratio, so its physical range is narrower than the
            // column: only 0 through 1 inclusive, at no more than four decimals.
            'power_factor' => [
                'bail',
                'required',
                'string',
                'numeric',
                'decimal:0,4',
                'regex:/^(0(\.[0-9]{1,4})?|1(\.0{1,4})?)$/',
            ],
            ...$this->mediaLibrary->rules(),
        ];

        foreach (self::MEASUREMENT_SCALES as $field => $scale) {
            if ($field === 'power_factor') {
                continue;
            }

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
    private function serializeRecord(PhotometricCurveCalibrationRecord $record): array
    {
        return [
            'id' => $record->id,
            ...$this->standardSnapshots->serialize($record),
            'equipment_system_id' => $record->equipment_system_id,
            'system_code' => $record->system_code,
            'system_name' => $record->system_name,
            'probe' => $record->probe,
            'test_distance' => $record->test_distance,
            'calibration_coefficient' => $record->calibration_coefficient,
            'peak_luminous_intensity' => $record->peak_luminous_intensity,
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
                ->map(fn (PhotometricCurveCalibrationEquipment $device): array => $this->snapshots->serialize($device))
                ->values(),
            'photos' => $this->mediaLibrary->serializeCollection($record, PhotometricCurveCalibrationRecord::PHOTO_COLLECTION),
            'files' => $this->mediaLibrary->serializeCollection($record, PhotometricCurveCalibrationRecord::FILE_COLLECTION),
        ];
    }
}

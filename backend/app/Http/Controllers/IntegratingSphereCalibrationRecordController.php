<?php

namespace App\Http\Controllers;

use App\Models\IntegratingSphereCalibrationEquipment;
use App\Models\IntegratingSphereCalibrationRecord;
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
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class IntegratingSphereCalibrationRecordController extends Controller
{
    private const RESOURCE = 'integrating_sphere_calibration_records';

    /** Namespaces the validation message keys this workflow returns. */
    private const MESSAGE_PREFIX = 'integrating_sphere_calibration';

    private const EQUIPMENT_TABLE = 'integrating_sphere_calibration_equipment';

    private const RECORDS_TABLE = 'integrating_sphere_calibration_records';

    /**
     * Measurement columns and the scale the form promises for each of them. The
     * same map drives validation and keeps the rules in step with the migration.
     */
    private const MEASUREMENT_SCALES = [
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
        'color_rendering_index' => ['-9999.9', '9999.9'],
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

    public function formOptions(Request $request): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE);

        $config = config('calibration.integrating_sphere', []);
        $modes = array_values(array_filter(
            $config['modes'] ?? [],
            fn (array $item): bool => ($item['status'] ?? 'active') === 'active',
        ));
        $sensitivities = array_values(array_filter(
            $config['sensitivities'] ?? [],
            fn (array $item): bool => ($item['status'] ?? 'active') === 'active',
        ));

        return response()->json([
            'data' => [
                'modes' => array_map(fn (array $item): array => ['code' => $item['code'], 'label' => $item['label']], $modes),
                'sensitivities' => array_map(fn (array $item): array => ['code' => $item['code'], 'label' => $item['label']], $sensitivities),
            ],
        ]);
    }

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
                ->map(fn (IntegratingSphereCalibrationRecord $record): array => $this->serializeRecord($record))
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
                ->map(fn (IntegratingSphereCalibrationEquipment $row): array => $this->ledger->serializeRow($row))
                ->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(Request $request, IntegratingSphereCalibrationRecord $record): JsonResponse
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
        $mode = $this->resolveCatalogOption('modes', $payload['mode_code'], 'mode_code');
        $sensitivity = $this->resolveCatalogOption('sensitivities', $payload['sensitivity_code'], 'sensitivity_code');

        $uploads = $this->mediaLibrary->validatedUploads($request, [], self::MESSAGE_PREFIX);
        $written = [];

        try {
            $record = DB::transaction(function () use ($request, $payload, $standard, $system, $equipment, $mode, $sensitivity, $uploads, &$written): IntegratingSphereCalibrationRecord {
                $record = IntegratingSphereCalibrationRecord::query()->create([
                    ...$this->standardSnapshots->columns($standard),
                    'equipment_system_id' => $system->id,
                    'system_code' => $system->code,
                    'system_name' => $system->name,
                    'mode_code' => $mode['code'],
                    'mode_label' => $mode['label'],
                    'sensitivity_code' => $sensitivity['code'],
                    'sensitivity_label' => $sensitivity['label'],
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

    public function update(Request $request, IntegratingSphereCalibrationRecord $record, AuditLogger $auditLogger): JsonResponse
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

        $mode = isset($payload['mode_code'])
            ? $this->resolveCatalogOption('modes', $payload['mode_code'], 'mode_code')
            : null;

        $sensitivity = isset($payload['sensitivity_code'])
            ? $this->resolveCatalogOption('sensitivities', $payload['sensitivity_code'], 'sensitivity_code')
            : null;

        $equipment = $this->subjects->equipmentFor($addedIds, self::MESSAGE_PREFIX);
        $record->load('media');
        $existingMediaIds = $this->mediaLibrary->existingMediaIds($record);
        $retainedMedia = $this->mediaLibrary->retainedMedia($record, $payload, self::MESSAGE_PREFIX);
        $uploads = $this->mediaLibrary->validatedUploads($request, $retainedMedia, self::MESSAGE_PREFIX);
        $before = $this->serializeRecord($record->load(['equipment', 'media']));
        $written = [];

        try {
            DB::transaction(function () use ($record, $payload, $standard, $system, $equipment, $mode, $sensitivity, $addedIds, $retainedIds, $uploads, $existingMediaIds, &$written): void {
                $systemData = $system !== null ? [
                    'equipment_system_id' => $system->id,
                    'system_code' => $system->code,
                    'system_name' => $system->name,
                ] : [];

                $record->update([
                    ...$this->standardSnapshots->columns($standard),
                    ...$systemData,
                    'mode_code' => $mode['code'] ?? $record->mode_code,
                    'mode_label' => $mode['label'] ?? $record->mode_label,
                    'sensitivity_code' => $sensitivity['code'] ?? $record->sensitivity_code,
                    'sensitivity_label' => $sensitivity['label'] ?? $record->sensitivity_label,
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

    public function destroy(Request $request, IntegratingSphereCalibrationRecord $record, AuditLogger $auditLogger): JsonResponse
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

    public function viewMedia(Request $request, IntegratingSphereCalibrationRecord $record, Media $media): BinaryFileResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE, $record);

        $media = $this->mediaLibrary->ownedMedia($record, $media, IntegratingSphereCalibrationRecord::PHOTO_COLLECTION);

        return $this->mediaLibrary->inlineResponse($media);
    }

    public function downloadMedia(Request $request, IntegratingSphereCalibrationRecord $record, Media $media, AuditLogger $auditLogger): BinaryFileResponse
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

    private function resolveCatalogOption(string $type, string $code, string $field): array
    {
        $options = config("calibration.integrating_sphere.{$type}", []);

        foreach ($options as $option) {
            if (($option['code'] ?? '') === $code && ($option['status'] ?? 'active') === 'active') {
                return $option;
            }
        }

        throw ValidationException::withMessages([
            $field => [self::MESSAGE_PREFIX."_{$field}_invalid"],
        ]);
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
        return IntegratingSphereCalibrationRecord::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('standard_no', 'like', "%{$search}%")
                    ->orWhere('standard_name', 'like', "%{$search}%")
                    ->orWhere('system_code', 'like', "%{$search}%")
                    ->orWhere('system_name', 'like', "%{$search}%")
                    ->orWhere('mode_label', 'like', "%{$search}%")
                    ->orWhere('sensitivity_label', 'like', "%{$search}%")
                    ->orWhereHas('equipment', fn (Builder $equipment): Builder => $equipment
                        ->where('equipment_no', 'like', "%{$search}%")
                        ->orWhere('equipment_name', 'like', "%{$search}%")));
            })
            ->when($request->filled('mode_code'), fn (Builder $query): Builder => $query->where('mode_code', $request->string('mode_code')->toString()))
            ->when($request->filled('sensitivity_code'), fn (Builder $query): Builder => $query->where('sensitivity_code', $request->string('sensitivity_code')->toString()))
            ->when($request->filled('date_from'), fn (Builder $query): Builder => $query->where('recorded_at', '>=', $request->string('date_from')->toString().' 00:00:00'))
            ->when($request->filled('date_to'), fn (Builder $query): Builder => $query->where('recorded_at', '<=', $request->string('date_to')->toString().' 23:59:59'));
    }

    private function equipmentLedgerQuery(Request $request): Builder
    {
        return $this->ledger->applyFilters(
            IntegratingSphereCalibrationEquipment::query(),
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
            'mode_code' => ['required', 'string'],
            'sensitivity_code' => ['required', 'string'],
            ...$this->sharedRules(),
        ];
    }

    /**
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
            'mode_code' => ['sometimes', 'string'],
            'sensitivity_code' => ['sometimes', 'string'],
            ...$this->sharedRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedRules(): array
    {
        $rules = [
            'remark' => ['nullable', 'string', 'max:2000'],
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
    private function serializeRecord(IntegratingSphereCalibrationRecord $record): array
    {
        return [
            'id' => $record->id,
            ...$this->standardSnapshots->serialize($record),
            'equipment_system_id' => $record->equipment_system_id,
            'system_code' => $record->system_code,
            'system_name' => $record->system_name,
            'mode_code' => $record->mode_code,
            'mode_label' => $record->mode_label,
            'sensitivity_code' => $record->sensitivity_code,
            'sensitivity_label' => $record->sensitivity_label,
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
                ->map(fn (IntegratingSphereCalibrationEquipment $device): array => $this->snapshots->serialize($device))
                ->values(),
            'photos' => $this->mediaLibrary->serializeCollection($record, IntegratingSphereCalibrationRecord::PHOTO_COLLECTION),
            'files' => $this->mediaLibrary->serializeCollection($record, IntegratingSphereCalibrationRecord::FILE_COLLECTION),
        ];
    }
}

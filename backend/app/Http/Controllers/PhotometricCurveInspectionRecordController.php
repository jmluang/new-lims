<?php

namespace App\Http\Controllers;

use App\Models\PhotometricCurveInspectionEquipment;
use App\Models\PhotometricCurveInspectionRecord;
use App\Models\Sample;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PermissionAccess;
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

class PhotometricCurveInspectionRecordController extends Controller
{
    private const RESOURCE = 'photometric_curve_inspection_records';

    /** Namespaces the validation message keys this workflow returns. */
    private const MESSAGE_PREFIX = 'photometric_curve';

    private const EQUIPMENT_TABLE = 'photometric_curve_inspection_equipment';

    private const RECORDS_TABLE = 'photometric_curve_inspection_records';

    private const PROBES = ['near_field', 'far_field'];

    /**
     * Measurement columns and the scale the workbook promises for each of them. The
     * same map drives validation and keeps the rules in step with the migration.
     *
     * The average angle is not here: it is derived from the four angle columns on
     * read, is never accepted from a client, and is never stored.
     */
    private const MEASUREMENT_SCALES = [
        'c0_180' => 1,
        'c30_210' => 1,
        'c60_240' => 1,
        'c90_270' => 1,
        'test_distance' => 4,
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
     * entry fails validation instead of overflowing on a strict-mode database. The
     * power factor is the one exception: it is a ratio, so the physical range is
     * tighter than the column that holds it.
     */
    private const INTEGER_BOUNDS = [
        'frequency' => [0, 1000000],
    ];

    private const DECIMAL_BOUNDS = [
        'c0_180' => [0, '9999.9'],
        'c30_210' => [0, '9999.9'],
        'c60_240' => [0, '9999.9'],
        'c90_270' => [0, '9999.9'],
        'test_distance' => [0, '99999999.9999'],
        'peak_luminous_intensity' => [0, '99999999999.9'],
        'luminous_flux' => [0, '99999999999.9'],
        'voltage' => [0, '99999999.99'],
        'current' => [0, '99999999.9999'],
        'power' => [0, '99999999.9999'],
        'power_factor' => [0, 1],
    ];

    /**
     * Array fields that a multipart body cannot express when they are empty.
     *
     * `retained_equipment_ids[]` with no entries simply does not appear in the
     * request, which the retention contract would read as "keep everything" — the
     * opposite of what an operator who cleared the list asked for. The editor
     * therefore always sends the field, as an empty string when the list is empty,
     * and it is normalised back to an empty array before validation. The empty string
     * has already become null by the time it gets here, because the global
     * `ConvertEmptyStringsToNull` middleware runs first.
     */
    private const NORMALIZED_ARRAY_FIELDS = ['retained_equipment_ids', 'retained_media_ids'];

    public function __construct(
        private readonly InspectionSubjectLookup $subjects,
        private readonly InspectionEquipmentSnapshots $snapshots,
        private readonly InspectionEquipmentLedger $ledger,
        private readonly InspectionMediaLibrary $mediaLibrary,
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
                ->map(fn (PhotometricCurveInspectionRecord $record): array => $this->serializeRecord($record))
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
                ->map(fn (PhotometricCurveInspectionEquipment $row): array => $this->ledger->serializeRow($row))
                ->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(Request $request, PhotometricCurveInspectionRecord $inspectionRecord): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE, $inspectionRecord);

        return response()->json(['data' => $this->serializeRecord($inspectionRecord->load(['equipment', 'media']))]);
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

        $this->normalizeArrayFields($request);
        $payload = $request->validate($this->storeRules());
        $sample = Sample::query()->findOrFail($payload['sample_id']);
        $system = $this->subjects->activeSystemFor((int) $payload['equipment_system_id'], self::MESSAGE_PREFIX);
        $equipment = $this->subjects->equipmentFor($payload['equipment_ids'], self::MESSAGE_PREFIX);
        $uploads = $this->mediaLibrary->validatedUploads($request, [], self::MESSAGE_PREFIX);
        $written = [];

        try {
            $record = DB::transaction(function () use ($request, $payload, $sample, $system, $equipment, $uploads, &$written): PhotometricCurveInspectionRecord {
                $record = PhotometricCurveInspectionRecord::query()->create([
                    'sample_id' => $sample->id,
                    'sample_no' => $sample->sample_no,
                    'equipment_system_id' => $system->id,
                    'system_code' => $system->code,
                    'system_name' => $system->name,
                    ...$this->measurementValues($payload),
                    'probe' => $payload['probe'],
                    'remark' => $this->normalizedRemark($payload),
                    // Server time, taken once here. The client never sends it and can
                    // never move it, which is what makes it usable as audit evidence.
                    'recorded_at' => Carbon::now()->microseconds(0),
                    'operator_id' => $request->user()?->id,
                    'operator_name' => $request->user()?->name,
                ]);

                $this->snapshots->sync($record, $payload['equipment_ids'], $equipment);
                // The record is new, so it owns no media yet and everything the attach
                // touches belongs to this request by construction.
                $this->mediaLibrary->attach($record, $uploads, [], $written);

                return $record;
            });
        } catch (Throwable $exception) {
            // The rolled-back transaction removed the media rows but not the bytes the
            // library already wrote, so the new files are cleaned up before the failure
            // is reported. Nothing existed before this request, so nothing else changes.
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

    public function update(Request $request, PhotometricCurveInspectionRecord $inspectionRecord, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.update', self::RESOURCE, $inspectionRecord);

        $this->normalizeArrayFields($request);
        $payload = $request->validate($this->updateRules());
        $addedIds = $payload['equipment_ids'] ?? [];
        $retainedIds = $this->snapshots->retainedChildIds($inspectionRecord, $payload, $addedIds, self::MESSAGE_PREFIX);
        // A re-declared sample re-snapshots from the ledger; an omitted one keeps the
        // snapshot already on the record, which is the only evidence left once the
        // ledger row is gone.
        $sample = isset($payload['sample_id']) ? Sample::query()->findOrFail($payload['sample_id']) : null;
        // Same retained/selected split as the sample: an omitted system keeps the code
        // and name snapshots the record already holds, which are the only evidence left
        // once the system has been renamed, disabled or deleted.
        $system = isset($payload['equipment_system_id'])
            ? $this->subjects->activeSystemFor((int) $payload['equipment_system_id'], self::MESSAGE_PREFIX)
            : null;
        $equipment = $this->subjects->equipmentFor($addedIds, self::MESSAGE_PREFIX);
        $inspectionRecord->load('media');
        $existingMediaIds = $this->mediaLibrary->existingMediaIds($inspectionRecord);
        $retainedMedia = $this->mediaLibrary->retainedMedia($inspectionRecord, $payload, self::MESSAGE_PREFIX);
        $uploads = $this->mediaLibrary->validatedUploads($request, $retainedMedia, self::MESSAGE_PREFIX);
        $before = $this->serializeRecord($inspectionRecord->load(['equipment', 'media']));
        $written = [];

        try {
            DB::transaction(function () use ($inspectionRecord, $payload, $sample, $system, $equipment, $addedIds, $retainedIds, $uploads, $existingMediaIds, &$written): void {
                $inspectionRecord->update([
                    'sample_id' => $sample?->id ?? $inspectionRecord->sample_id,
                    'sample_no' => $sample?->sample_no ?? $inspectionRecord->sample_no,
                    'equipment_system_id' => $system?->id ?? $inspectionRecord->equipment_system_id,
                    'system_code' => $system?->code ?? $inspectionRecord->system_code,
                    'system_name' => $system?->name ?? $inspectionRecord->system_name,
                    ...$this->measurementValues($payload),
                    'probe' => $payload['probe'],
                    'remark' => $this->normalizedRemark($payload),
                    // `recorded_at` is deliberately absent: the time the measurement was
                    // taken is not something an edit gets to rewrite.
                ]);

                // Retained children are never rewritten, so a snapshot whose ledger row was
                // edited or deleted keeps the values the measurement was actually taken with.
                $inspectionRecord->equipment()->whereNotIn('id', $retainedIds)->delete();
                $this->snapshots->sync($inspectionRecord, $addedIds, $equipment);
                // Scoped to this record's media, and to the ids it already had, so the
                // cleanup below can only ever reach what this request created.
                $this->mediaLibrary->attach($inspectionRecord, $uploads, $existingMediaIds, $written);
            });
        } catch (Throwable $exception) {
            $this->mediaLibrary->discardFiles($written);

            throw $exception;
        }

        // Dropping the media the operator removed is the last step and happens only
        // once everything else has committed: a failure above must leave the record
        // and its previous attachments exactly as they were.
        $this->mediaLibrary->deleteRemoved($inspectionRecord, $retainedMedia, $written);

        $record = $inspectionRecord->fresh(['equipment', 'media']);

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

    public function destroy(Request $request, PhotometricCurveInspectionRecord $inspectionRecord, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.delete', self::RESOURCE, $inspectionRecord);

        $before = $this->serializeRecord($inspectionRecord->load(['equipment', 'media']));
        $inspectionRecord->delete();

        $auditLogger->record(
            actor: $request->user(),
            action: self::RESOURCE.'.delete',
            module: self::RESOURCE,
            before: $before,
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Streams one photo inline for the editor and the detail thumbnails.
     *
     * Only the photo collection answers here and only with the image types the
     * collection accepts, so nothing that a browser could execute is ever served
     * inline. Views are not audited: a detail modal loads every thumbnail at once and
     * that noise would bury the download evidence that actually matters.
     */
    public function viewMedia(Request $request, PhotometricCurveInspectionRecord $inspectionRecord, Media $media): BinaryFileResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE, $inspectionRecord);

        $media = $this->mediaLibrary->ownedMedia($inspectionRecord, $media, PhotometricCurveInspectionRecord::PHOTO_COLLECTION);

        return $this->mediaLibrary->inlineResponse($media);
    }

    public function downloadMedia(Request $request, PhotometricCurveInspectionRecord $inspectionRecord, Media $media, AuditLogger $auditLogger): BinaryFileResponse
    {
        $this->authorizePermission($request, self::RESOURCE.'.read', self::RESOURCE, $inspectionRecord);

        $media = $this->mediaLibrary->ownedMedia($inspectionRecord, $media);

        // Metadata only: the audit trail records which attachment left the system, and
        // never the bytes or the private path it was read from.
        $auditLogger->record(
            actor: $request->user(),
            action: self::RESOURCE.'.media.download',
            module: self::RESOURCE,
            subject: $inspectionRecord,
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

    /** @see self::NORMALIZED_ARRAY_FIELDS */
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
            // A multipart body delivers every value as a string; the integer columns are
            // cast back here so the model stores a number rather than "50".
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
        return PhotometricCurveInspectionRecord::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('sample_no', 'like', "%{$search}%")
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
            PhotometricCurveInspectionEquipment::query(),
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
        // `recorded_at` is intentionally not a rule. It is a server-owned audit value:
        // omitting it from the writable set means a payload that carries one has it
        // stripped by validation rather than quietly honoured.
        $rules = [
            'remark' => ['nullable', 'string', 'max:2000'],
            'probe' => ['required', 'string', 'in:'.implode(',', self::PROBES)],
            ...$this->mediaLibrary->rules(),
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
    private function serializeRecord(PhotometricCurveInspectionRecord $record): array
    {
        return [
            'id' => $record->id,
            'sample_id' => $record->sample_id,
            'sample_no' => $record->sample_no,
            'equipment_system_id' => $record->equipment_system_id,
            'system_code' => $record->system_code,
            'system_name' => $record->system_name,
            'c0_180' => $record->c0_180,
            'c30_210' => $record->c30_210,
            'c60_240' => $record->c60_240,
            'c90_270' => $record->c90_270,
            // Derived on every read, never stored and never writable.
            'average_angle' => $record->averageAngle(),
            'probe' => $record->probe,
            'test_distance' => $record->test_distance,
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
                ->map(fn (PhotometricCurveInspectionEquipment $device): array => $this->snapshots->serialize($device))
                ->values(),
            'photos' => $this->mediaLibrary->serializeCollection($record, PhotometricCurveInspectionRecord::PHOTO_COLLECTION),
            'files' => $this->mediaLibrary->serializeCollection($record, PhotometricCurveInspectionRecord::FILE_COLLECTION),
        ];
    }
}

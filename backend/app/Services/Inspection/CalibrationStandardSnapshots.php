<?php

namespace App\Services\Inspection;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Model;

/**
 * The standard-device evidence rules shared by the calibration aggregates: resolving
 * the submitted ledger row, writing the immutable seven-field snapshot, and reading
 * that snapshot back out.
 *
 * Both calibration workflows record exactly one standard from the equipment ledger
 * and file it the same way — the snapshot is written on create, retained verbatim
 * when an edit omits it, and replaced as a whole when the operator re-scans. Only
 * the surrounding measurement contract differs, so the part that is easy to get
 * subtly wrong is written once here.
 */
class CalibrationStandardSnapshots
{
    /**
     * The standard a create payload names. `exists` has already rejected an unknown
     * id, so a failure here means a concurrent request removed the ledger row.
     *
     * @param  array<string, mixed>  $payload
     */
    public function requiredFor(array $payload): Equipment
    {
        return Equipment::query()->findOrFail($payload['standard_equipment_id']);
    }

    /**
     * The standard an edit names, or null when the payload omits it. Omission is the
     * operator keeping the stored snapshot, not a request to clear it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function optionalFor(array $payload): ?Equipment
    {
        return isset($payload['standard_equipment_id'])
            ? Equipment::query()->findOrFail($payload['standard_equipment_id'])
            : null;
    }

    /**
     * The snapshot columns for a selected standard, or an empty set for none.
     *
     * The whole snapshot is written together so replacing a fully described standard
     * with a sparse one clears the fields the previous device filled instead of
     * leaving its manufacturer or serial number attached to a different device.
     *
     * @return array<string, mixed>
     */
    public function columns(?Equipment $standard): array
    {
        if ($standard === null) {
            return [];
        }

        return [
            'standard_equipment_id' => $standard->id,
            'standard_no' => $standard->equipment_no,
            'standard_name' => $standard->name,
            'standard_manufacturer' => $standard->manufacturer,
            'standard_model' => $standard->model,
            'standard_serial_no' => $standard->serial_no,
            'standard_next_calibration_date' => $standard->next_calibration_date,
        ];
    }

    /**
     * The stored snapshot as the API returns it — read from the record's own columns,
     * never joined back to the live ledger row it was taken from.
     *
     * @return array<string, mixed>
     */
    public function serialize(Model $record): array
    {
        return [
            'standard_equipment_id' => $record->standard_equipment_id,
            'standard_no' => $record->standard_no,
            'standard_name' => $record->standard_name,
            'standard_manufacturer' => $record->standard_manufacturer,
            'standard_model' => $record->standard_model,
            'standard_serial_no' => $record->standard_serial_no,
            'standard_next_calibration_date' => $record->standard_next_calibration_date?->toDateString(),
        ];
    }
}

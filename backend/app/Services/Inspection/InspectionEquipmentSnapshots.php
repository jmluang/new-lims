<?php

namespace App\Services\Inspection;

use App\Models\Equipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The used-equipment evidence rules shared by every inspection aggregate: writing an
 * immutable snapshot of a device, deciding which stored snapshots survive an edit,
 * and serializing a stored snapshot back out.
 *
 * The child rows live in a table per aggregate — dedicated typed columns, dedicated
 * indexes — but the retention contract is identical, and it is the part that is easy
 * to get subtly wrong, so it is written once here.
 */
class InspectionEquipmentSnapshots
{
    /**
     * @param  array<int, int>  $equipmentIds
     * @param  Collection<int, Equipment>  $equipment
     */
    public function sync(Model $record, array $equipmentIds, Collection $equipment): void
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
    public function retainedChildIds(Model $record, array $payload, array $addedIds, string $messagePrefix): array
    {
        $children = $record->equipment()->get();

        if (! array_key_exists('retained_equipment_ids', $payload)) {
            $retained = $children->pluck('id')->all();
        } else {
            $retained = array_values(array_map('intval', $payload['retained_equipment_ids']));
            $ownIds = $children->pluck('id')->all();

            if (array_diff($retained, $ownIds) !== []) {
                throw ValidationException::withMessages([
                    'retained_equipment_ids' => [$messagePrefix.'_retained_equipment_invalid'],
                ]);
            }
        }

        if ($retained === [] && $addedIds === []) {
            throw ValidationException::withMessages([
                'equipment_ids' => [$messagePrefix.'_equipment_required'],
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
                'equipment_ids' => [$messagePrefix.'_equipment_already_retained'],
            ]);
        }

        return $retained;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Model $device): array
    {
        return [
            'id' => $device->id,
            'equipment_id' => $device->equipment_id,
            'equipment_no' => $device->equipment_no,
            'equipment_name' => $device->equipment_name,
            'manufacturer' => $device->manufacturer,
            'model' => $device->model,
            'serial_no' => $device->serial_no,
            'next_calibration_date' => $device->next_calibration_date?->toDateString(),
        ];
    }
}

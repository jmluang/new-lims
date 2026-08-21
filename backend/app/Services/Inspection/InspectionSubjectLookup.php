<?php

namespace App\Services\Inspection;

use App\Models\Equipment;
use App\Models\EquipmentSystem;
use App\Models\Sample;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the three codes an inspection form scans — an equipment number, a sample
 * number and an equipment system code — and serializes them for the lookup endpoint.
 *
 * Every inspection workflow scans the same three ledgers with the same rules, so the
 * resolution lives here once. What stays with each workflow is the validation
 * message namespace, which is passed in so an error still names the domain that
 * raised it and existing API payloads keep the exact strings they already return.
 */
class InspectionSubjectLookup
{
    public function equipmentByNo(string $code): Equipment
    {
        return Equipment::query()->where('equipment_no', $code)->firstOrFail();
    }

    /**
     * A system code is an independent operator input, never inferred from the
     * devices. Only an active system can answer a fresh scan; a disabled one stays
     * valid history on the records that already carry its snapshot.
     */
    public function activeSystemByCode(string $code): EquipmentSystem
    {
        return EquipmentSystem::query()->where('code', $code)->where('status', 'active')->firstOrFail();
    }

    public function sampleByNo(string $code): Sample
    {
        return Sample::query()->where('sample_no', $code)->firstOrFail();
    }

    /**
     * Resolves an explicitly scanned or typed system.
     *
     * The `exists` rule already rejects an unknown id, so a failure here means the row
     * is not selectable: it was disabled, or a concurrent request deleted it. Either
     * way a new selection must point at a live active system, while the records that
     * already reference it keep their snapshot untouched.
     */
    public function activeSystemFor(int $systemId, string $messagePrefix): EquipmentSystem
    {
        $system = EquipmentSystem::query()->whereKey($systemId)->first();

        if ($system === null || $system->status !== 'active') {
            throw ValidationException::withMessages([
                'equipment_system_id' => [$messagePrefix.'_system_inactive'],
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
    public function equipmentFor(array $equipmentIds, string $messagePrefix): Collection
    {
        $equipment = Equipment::query()->whereIn('id', $equipmentIds)->get()->keyBy('id');

        if ($equipment->count() !== count($equipmentIds)) {
            throw ValidationException::withMessages(['equipment_ids' => [$messagePrefix.'_equipment_missing']]);
        }

        return $equipment;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeEquipmentOption(Equipment $equipment): array
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
    public function serializeSystemOption(EquipmentSystem $system): array
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
    public function serializeSampleOption(Sample $sample): array
    {
        return [
            'id' => $sample->id,
            'sample_no' => $sample->sample_no,
            'sample_name' => $sample->sample_name,
            'model' => $sample->model,
            'status' => $sample->status,
        ];
    }
}

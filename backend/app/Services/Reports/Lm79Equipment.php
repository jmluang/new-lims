<?php

namespace App\Services\Reports;

use App\Models\Equipment;
use App\Services\Inspection\InspectionSubjectLookup;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Ledger identities are authoritative; retained rows keep their historical values. */
final class Lm79Equipment
{
    public function option(Equipment $equipment): array
    {
        $row = app(InspectionSubjectLookup::class)->serializeEquipmentOption($equipment);

        return ['equipment_id' => $row['id'], 'equipment_no' => $row['equipment_no'],
            'name' => $row['equipment_name'], 'manufacturer' => $row['manufacturer'] ?? '', 'model' => $row['model'] ?? '',
            'serial' => $row['serial_no'] ?? '', 'next_calibration_date' => $row['next_calibration_date'] ?? '',
            'cal_cert' => '', 'cal_org' => '', 'cal_due' => $row['next_calibration_date'] ?? ''];
    }

    public function withKeys(array $rows): array
    {
        return array_map(fn ($row, $index) => array_replace(['equipment_no' => '', 'manufacturer' => '', 'model' => '', 'serial' => '', 'next_calibration_date' => '', 'cal_cert' => '', 'cal_org' => '', 'cal_due' => ''], $row, ['snapshot_id' => $row['snapshot_id'] ?? 'legacy-'.$index]), $rows, array_keys($rows));
    }

    public function resolve(array $submitted, array $existing): array
    {
        $owned = collect($this->withKeys($existing))->keyBy('snapshot_id');
        $newIds = collect($submitted)->filter(fn ($row) => empty($row['snapshot_id']))->pluck('equipment_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $devices = app(InspectionSubjectLookup::class)->equipmentFor($newIds, 'lm79_reports');
        $result = [];
        $seenKeys = [];
        $seenDevices = [];
        foreach ($submitted as $index => $row) {
            $key = $row['snapshot_id'] ?? null;
            if ($key !== null) {
                if (! $owned->has($key) || isset($seenKeys[$key])) {
                    throw ValidationException::withMessages(['equipment' => ['设备快照不属于此报告或被重复提交。']]);
                }
                $snapshot = $owned->get($key);
                $seenKeys[$key] = true;
            } else {
                $id = (int) ($row['equipment_id'] ?? 0);
                if ($id <= 0 || ! $devices->has($id)) {
                    throw ValidationException::withMessages(['equipment' => ['请扫码或输入设备编号，从设备台账添加设备。']]);
                }
                $snapshot = [...$this->option($devices->get($id)), 'snapshot_id' => (string) Str::uuid()];
            }
            $deviceId = $snapshot['equipment_id'] ?? null;
            if ($deviceId !== null) {
                if (isset($seenDevices[$deviceId])) {
                    throw ValidationException::withMessages(['equipment' => ['同一设备不能重复添加。']]);
                }
                $seenDevices[$deviceId] = true;
            }
            // Certificate information is supplemental; device identity is never accepted from the client.
            foreach (['cal_cert', 'cal_org', 'cal_due'] as $field) {
                if (array_key_exists($field, $row)) {
                    $snapshot[$field] = $row[$field] ?? '';
                }
            }
            $result[] = $snapshot;
        }

        return $result;
    }
}

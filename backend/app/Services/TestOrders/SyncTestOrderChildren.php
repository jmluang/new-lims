<?php

namespace App\Services\TestOrders;

use App\Models\TestOrder;
use App\Models\TestOrderSample;
use App\Models\TestOrderStandard;
use Illuminate\Validation\ValidationException;

class SyncTestOrderChildren
{
    /**
     * @param  array<int, array<string, mixed>>  $standards
     * @param  array<int, array<string, mixed>>  $samples
     */
    public function sync(TestOrder $testOrder, array $standards, array $samples): void
    {
        $this->syncStandards($testOrder, $standards);
        $this->syncSamples($testOrder, $samples);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncStandards(TestOrder $testOrder, array $rows): void
    {
        $existing = $testOrder->standards()->get()->keyBy('id');
        $keptIds = [];

        foreach (array_values($rows) as $sortOrder => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            unset($row['id']);

            $data = [
                'standard_id' => $row['standard_id'] ?? null,
                'standard_code' => $row['standard_code'],
                'standard_name' => $row['standard_name'],
                'report_language' => $row['report_language'] ?? null,
                'qualifications' => $row['qualifications'] ?? [],
                'requirement' => $row['requirement'] ?? null,
                'sort_order' => $sortOrder,
            ];

            if ($id !== null) {
                $standard = $existing->get($id);

                if (! $standard instanceof TestOrderStandard) {
                    throw ValidationException::withMessages([
                        'standards' => ['test_order_standard_id_mismatch'],
                    ]);
                }

                $standard->update($data);
                $keptIds[] = $id;

                continue;
            }

            $created = $testOrder->standards()->create($data);
            $keptIds[] = $created->id;
        }

        $testOrder->standards()
            ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncSamples(TestOrder $testOrder, array $rows): void
    {
        $existing = $testOrder->samples()->get()->keyBy('id');
        $keptIds = [];

        foreach (array_values($rows) as $sortOrder => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            unset($row['id']);

            $data = [
                'sample_name' => $row['sample_name'],
                'specification' => $row['specification'] ?? null,
                'model' => $row['model'] ?? null,
                'input_voltage' => $row['input_voltage'] ?? null,
                'rated_current' => $row['rated_current'] ?? null,
                'power' => $row['power'] ?? null,
                'rated_frequency' => $row['rated_frequency'] ?? null,
                'status' => $row['status'] ?? 'pending',
                'quantity' => $row['quantity'] ?? 1,
                'quantity_unit' => $row['quantity_unit'] ?? null,
                'sample_condition' => $row['sample_condition'] ?? null,
                'sample_condition_note' => $row['sample_condition_note'] ?? null,
                'detail_content' => $row['detail_content'] ?? null,
                'remark' => $row['remark'] ?? null,
                'sort_order' => $sortOrder,
            ];

            if ($id !== null) {
                $sample = $existing->get($id);

                if (! $sample instanceof TestOrderSample) {
                    throw ValidationException::withMessages([
                        'samples' => ['test_order_sample_id_mismatch'],
                    ]);
                }

                $sample->update($data);
                $keptIds[] = $id;

                continue;
            }

            $created = $testOrder->samples()->create($data);
            $keptIds[] = $created->id;
        }

        $testOrder->samples()
            ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
    }
}

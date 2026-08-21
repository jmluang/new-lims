<?php

namespace App\Services\Inspection;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The flattened used-equipment ledger shared by the inspection aggregates: every
 * device association across every record of one workflow, joined to its parent so
 * the equipment fields keep coming from the immutable child snapshot and the date
 * and operator from the immutable parent.
 *
 * The two table names are parameters rather than hard-coded because each aggregate
 * owns its own child table. They are class constants of the calling controller, never
 * request input, and the guard below keeps it that way: anything but a plain
 * identifier is refused before it can reach a raw join clause.
 */
class InspectionEquipmentLedger
{
    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function applyFilters(Builder $query, Request $request, string $table, string $parentTable): Builder
    {
        $this->assertIdentifier($table);
        $this->assertIdentifier($parentTable);

        return $query
            ->join($parentTable.' as parent', 'parent.id', '=', $table.'.inspection_record_id')
            ->when($request->filled('search'), function (Builder $builder) use ($request, $table): void {
                $search = $request->string('search')->toString();
                $builder->where(function (Builder $inner) use ($search, $table): void {
                    $inner
                        ->where($table.'.equipment_no', 'like', "%{$search}%")
                        ->orWhere($table.'.equipment_name', 'like', "%{$search}%")
                        ->orWhere($table.'.manufacturer', 'like', "%{$search}%")
                        ->orWhere($table.'.model', 'like', "%{$search}%")
                        ->orWhere($table.'.serial_no', 'like', "%{$search}%");

                    // All three ids the table shows are searchable, but only as whole
                    // numbers: a substring match on an id would make "1" pull in every
                    // record from 10 up.
                    if (ctype_digit($search)) {
                        $inner
                            ->orWhere($table.'.id', (int) $search)
                            ->orWhere($table.'.inspection_record_id', (int) $search)
                            ->orWhere($table.'.equipment_id', (int) $search);
                    }
                });
            })
            ->when(
                $request->filled('inspection_record_id'),
                fn (Builder $builder): Builder => $builder->where($table.'.inspection_record_id', $request->integer('inspection_record_id')),
            )
            ->when(
                $request->filled('equipment_id'),
                fn (Builder $builder): Builder => $builder->where($table.'.equipment_id', $request->integer('equipment_id')),
            )
            ->when(
                $request->filled('date_from'),
                fn (Builder $builder): Builder => $builder->where('parent.recorded_at', '>=', $request->string('date_from')->toString().' 00:00:00'),
            )
            ->when(
                $request->filled('date_to'),
                fn (Builder $builder): Builder => $builder->where('parent.recorded_at', '<=', $request->string('date_to')->toString().' 23:59:59'),
            );
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public function applyOrdering(Builder $query, string $table): Builder
    {
        $this->assertIdentifier($table);

        return $query
            ->select($table.'.*')
            ->orderByDesc('parent.recorded_at')
            ->orderByDesc($table.'.inspection_record_id')
            ->orderBy($table.'.id');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function serializeRow(Model $row, array $extra = []): array
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
            ...$extra,
        ];
    }

    private function assertIdentifier(string $name): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Inspection ledger table names must be plain identifiers.');
        }
    }
}

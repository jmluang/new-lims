<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'equipment_id',
    'sample_id',
    'usage_batch_id',
    'equipment_no',
    'equipment_name',
    'sample_no',
    'sample_name',
    'sample_model',
    'start_time',
    'end_time',
    'operator_id',
    'operator_name',
    'remark',
])]
class EquipmentUsageRecord extends Model
{
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}

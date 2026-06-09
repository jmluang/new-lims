<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'equipment_no',
    'name',
    'manufacturer',
    'model',
    'serial_no',
    'location_id',
    'purchase_date',
    'enable_date',
    'calibration_date',
    'calibration_duration',
    'next_calibration_date',
    'status',
    'device_image',
    'manual_files',
    'instruction_files',
    'calibration_files',
    'other_files',
    'remark',
])]
class Equipment extends Model
{
    protected $table = 'equipment';

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'enable_date' => 'date',
            'calibration_date' => 'date',
            'next_calibration_date' => 'date',
            'manual_files' => 'array',
            'instruction_files' => 'array',
            'calibration_files' => 'array',
            'other_files' => 'array',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(EquipmentLocation::class, 'location_id');
    }
}

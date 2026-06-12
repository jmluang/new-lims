<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'equipment_calibration_id',
    'equipment_id',
    'equipment_no',
    'equipment_name',
    'equipment_model',
    'calibration_date',
    'remark',
])]
class EquipmentCalibrationDevice extends Model
{
    protected function casts(): array
    {
        return [
            'calibration_date' => 'date',
        ];
    }

    public function calibration(): BelongsTo
    {
        return $this->belongsTo(EquipmentCalibration::class, 'equipment_calibration_id');
    }
}

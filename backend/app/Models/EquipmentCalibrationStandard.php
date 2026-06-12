<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'equipment_calibration_id',
    'equipment_id',
    'standard_no',
    'standard_name',
    'standard_model',
    'calibration_date',
    'remark',
])]
class EquipmentCalibrationStandard extends Model
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

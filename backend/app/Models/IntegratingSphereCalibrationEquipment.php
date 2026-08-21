<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'calibration_record_id',
    'equipment_id',
    'equipment_no',
    'equipment_name',
    'manufacturer',
    'model',
    'serial_no',
    'next_calibration_date',
])]
class IntegratingSphereCalibrationEquipment extends Model
{
    protected $table = 'integrating_sphere_calibration_equipment';

    protected function casts(): array
    {
        return [
            'next_calibration_date' => 'date',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(IntegratingSphereCalibrationRecord::class, 'calibration_record_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}

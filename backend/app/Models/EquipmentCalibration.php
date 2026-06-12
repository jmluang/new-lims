<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'calibration_project_id',
    'calibration_name',
    'calibration_time',
    'operator_id',
    'operator_name',
    'result',
    'remark',
    'attachment_files',
    'photo_files',
])]
class EquipmentCalibration extends Model
{
    protected function casts(): array
    {
        return [
            'calibration_time' => 'datetime',
            'attachment_files' => 'array',
            'photo_files' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(CalibrationProject::class, 'calibration_project_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(EquipmentCalibrationDevice::class)->orderBy('id');
    }

    public function standards(): HasMany
    {
        return $this->hasMany(EquipmentCalibrationStandard::class)->orderBy('id');
    }
}

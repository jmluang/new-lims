<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sample_id',
    'sample_no',
    'equipment_system_id',
    'system_code',
    'chromaticity_x',
    'chromaticity_y',
    'dominant_wavelength',
    'peak_wavelength',
    'color_temperature',
    'color_rendering_index',
    'luminous_flux',
    'voltage',
    'current',
    'power',
    'power_factor',
    'frequency',
    'remark',
    'recorded_at',
    'operator_id',
    'operator_name',
])]
class IntegratingSphereInspectionRecord extends Model
{
    /**
     * Measurements are cast to fixed-scale decimals so the API returns the exact
     * scale the operator typed instead of a binary floating point approximation.
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'chromaticity_x' => 'decimal:4',
            'chromaticity_y' => 'decimal:4',
            'dominant_wavelength' => 'decimal:1',
            'peak_wavelength' => 'decimal:1',
            'color_temperature' => 'integer',
            'color_rendering_index' => 'decimal:1',
            'luminous_flux' => 'decimal:1',
            'voltage' => 'decimal:1',
            'current' => 'decimal:4',
            'power' => 'decimal:4',
            'power_factor' => 'decimal:4',
            'frequency' => 'integer',
        ];
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    public function equipmentSystem(): BelongsTo
    {
        return $this->belongsTo(EquipmentSystem::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(IntegratingSphereInspectionEquipment::class, 'inspection_record_id')->orderBy('id');
    }
}

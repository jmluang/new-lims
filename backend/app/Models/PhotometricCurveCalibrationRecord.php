<?php

namespace App\Models;

use App\Services\Inspection\InspectionMediaLibrary;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'standard_equipment_id',
    'standard_no',
    'standard_name',
    'standard_manufacturer',
    'standard_model',
    'standard_serial_no',
    'standard_next_calibration_date',
    'equipment_system_id',
    'system_code',
    'system_name',
    'probe',
    'test_distance',
    'calibration_coefficient',
    'peak_luminous_intensity',
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
class PhotometricCurveCalibrationRecord extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const PHOTO_COLLECTION = 'photos';

    public const FILE_COLLECTION = 'files';

    public const MEDIA_DISK = 'inspection_media';

    /**
     * Measurements are cast to fixed-scale decimals so the API returns the exact
     * scale the operator typed instead of a binary floating point approximation.
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'standard_next_calibration_date' => 'date',
            'test_distance' => 'decimal:4',
            'calibration_coefficient' => 'decimal:4',
            'peak_luminous_intensity' => 'decimal:1',
            'luminous_flux' => 'decimal:1',
            'voltage' => 'decimal:1',
            'current' => 'decimal:4',
            'power' => 'decimal:4',
            'power_factor' => 'decimal:4',
            'frequency' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $library = app(InspectionMediaLibrary::class);

        foreach ([self::PHOTO_COLLECTION, self::FILE_COLLECTION] as $collection) {
            $this->addMediaCollection($collection)
                ->useDisk(self::MEDIA_DISK)
                ->acceptsMimeTypes($library->acceptedMimeTypes($collection));
        }
    }

    public function standardEquipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'standard_equipment_id');
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
        return $this->hasMany(PhotometricCurveCalibrationEquipment::class, 'calibration_record_id')->orderBy('id');
    }
}

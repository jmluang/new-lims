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
    'sample_id',
    'sample_no',
    'equipment_system_id',
    'system_code',
    'system_name',
    'c0_180',
    'c30_210',
    'c60_240',
    'c90_270',
    'probe',
    'test_distance',
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
class PhotometricCurveInspectionRecord extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** The angle columns the average is derived from, in the order the form shows them. */
    public const ANGLE_FIELDS = ['c0_180', 'c30_210', 'c60_240', 'c90_270'];

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
            'c0_180' => 'decimal:1',
            'c30_210' => 'decimal:1',
            'c60_240' => 'decimal:1',
            'c90_270' => 'decimal:1',
            'test_distance' => 'decimal:4',
            'peak_luminous_intensity' => 'decimal:1',
            'luminous_flux' => 'decimal:1',
            'voltage' => 'decimal:1',
            'current' => 'decimal:4',
            'power' => 'decimal:4',
            'power_factor' => 'decimal:4',
            'frequency' => 'integer',
        ];
    }

    /**
     * Photos and documents live on a private disk and are only ever reached through
     * the authenticated, record-scoped media endpoints — no conversion is registered,
     * so nothing is ever written to a publicly served path.
     *
     * The accepted content types come from the same service that validates the upload
     * rather than being restated here. A second list would be free to drift, and a
     * drift in this direction turns a file the request rules accepted into a 500 from
     * inside the library instead of a 422 from the request.
     */
    public function registerMediaCollections(): void
    {
        $library = app(InspectionMediaLibrary::class);

        foreach ([self::PHOTO_COLLECTION, self::FILE_COLLECTION] as $collection) {
            $this->addMediaCollection($collection)
                ->useDisk(self::MEDIA_DISK)
                ->acceptsMimeTypes($library->acceptedMimeTypes($collection));
        }
    }

    /**
     * The average of the four measured angles, derived rather than stored.
     *
     * The workbook carried a hand-typed average that could drift away from the angles
     * beside it; computing it on read makes that impossible. The arithmetic runs on
     * integer tenths so it never touches a float: each angle is exactly one decimal
     * place, and a quarter of their sum is rounded half up back to one decimal place.
     */
    public function averageAngle(): string
    {
        return self::averageAngleOf(array_map(fn (string $field): string => (string) $this->{$field}, self::ANGLE_FIELDS));
    }

    /**
     * @param  array<int, string>  $angles  canonical one-decimal values
     */
    public static function averageAngleOf(array $angles): string
    {
        $total = 0;

        foreach ($angles as $angle) {
            $total += self::tenths($angle);
        }

        $count = count($angles);
        $quotient = intdiv($total, $count);
        $remainder = $total % $count;
        // Half up: the remainder is a fraction of `count`, so doubling it and comparing
        // against `count` decides the tie without dividing.
        $rounded = $quotient + ($remainder * 2 >= $count ? 1 : 0);

        return sprintf('%d.%d', intdiv($rounded, 10), $rounded % 10);
    }

    /** Reads a canonical non-negative one-decimal value as an integer number of tenths. */
    private static function tenths(string $value): int
    {
        [$integer, $fraction] = array_pad(explode('.', trim($value), 2), 2, '0');

        return ((int) $integer) * 10 + (int) substr(str_pad($fraction, 1, '0'), 0, 1);
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
        return $this->hasMany(PhotometricCurveInspectionEquipment::class, 'inspection_record_id')->orderBy('id');
    }
}

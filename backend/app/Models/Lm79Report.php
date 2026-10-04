<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Lm79Report extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const RENDER_COLLECTIONS = ['photos', 'pdf_gonio', 'pdf_sphere'];

    public const RENDER_MEDIA_LIMIT = 16 * 1024 * 1024;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sample_snapshot' => 'array', 'data' => 'array'];
    }

    public function registerMediaCollections(): void
    {
        foreach (['photos', 'pdf_gonio', 'pdf_sphere', 'ies', 'gos', 'haas'] as $name) {
            $this->addMediaCollection($name)->useDisk('inspection_media');
        }
    }

    public function spectrumPoints(): HasMany
    {
        return $this->hasMany(Lm79SpectrumPoint::class, 'report_id')->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class, 'pdf_document_id');
    }

    public function spectrumText(): string
    {
        return $this->spectrumPoints->map(fn ($p) => $p->wavelength.' '.$p->relative_power)->implode("\n");
    }
}

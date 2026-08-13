<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A 骑缝章 (perforation seal): the Java signer slices this image across page
 * edges so the seal only lines up when every page of the original is present.
 */
#[Fillable([
    'name',
    'appearance_image_path',
    'description',
    'signature_contact',
    'signature_location',
    'signature_reason',
    'is_default',
    'is_active',
])]
class PerforationStamp extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PerforationStamp $stamp): void {
            if ($stamp->is_default && $stamp->isDirty('is_default')) {
                static::query()
                    ->when($stamp->exists, fn (Builder $query) => $query->whereKeyNot($stamp->getKey()))
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

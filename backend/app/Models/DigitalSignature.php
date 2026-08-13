<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A first-page seal configuration: the stamp image plus the PKCS#7 metadata the
 * Java signer writes into the signature dictionary.
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
class DigitalSignature extends Model
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
        static::saving(function (DigitalSignature $signature): void {
            if ($signature->is_default && $signature->isDirty('is_default')) {
                static::query()
                    ->when($signature->exists, fn (Builder $query) => $query->whereKeyNot($signature->getKey()))
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

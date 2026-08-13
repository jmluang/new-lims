<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A declaration/statement page (声明页) that is merged in front of the report
 * before signing. The merge itself happens in the browser with pdf-lib, so the
 * signed bytes and the bytes the operator downloaded are the same object.
 */
#[Fillable([
    'name',
    'language',
    'description',
    'file_name',
    'file_path',
    'file_size',
    'mime_type',
    'is_default',
    'is_active',
    'created_by',
])]
class CertificateTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'file_size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Default is scoped per language: each language may have its own default.
        static::saving(function (CertificateTemplate $template): void {
            if ($template->is_default && $template->isDirty('is_default')) {
                static::query()
                    ->when($template->exists, fn (Builder $query) => $query->whereKeyNot($template->getKey()))
                    ->where('language', $template->language)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByLanguage(Builder $query, string $language): Builder
    {
        return $query->where('language', $language);
    }
}

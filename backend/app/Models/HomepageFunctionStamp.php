<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 首页功能章 — small badges laid out left-to-right at the top of page one.
 * Unlike the other seals several may be selected at once, and the order the
 * signing request sends them in is the order they are drawn.
 */
#[Fillable([
    'name',
    'image_path',
    'is_default',
    'is_active',
    'sort_order',
])]
class HomepageFunctionStamp extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

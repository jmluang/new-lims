<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'standard_id',
    'parent_id',
    'code',
    'name',
    'content',
    'sort_order',
    'created_by',
    'updated_by',
])]
class StandardCatalog extends Model
{
    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }
}

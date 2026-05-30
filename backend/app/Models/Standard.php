<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'std_no',
    'chinese_name',
    'publish_date',
    'implement_date',
    'status',
    'abolish_date',
    'replaced_by',
    'corresponding_std',
    'category',
    'language',
    'operator_id',
])]
class Standard extends Model
{
    protected function casts(): array
    {
        return [
            'publish_date' => 'date',
            'implement_date' => 'date',
            'abolish_date' => 'date',
        ];
    }

    public function catalogs(): HasMany
    {
        return $this->hasMany(StandardCatalog::class)->orderBy('sort_order')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StandardItem::class)->orderBy('id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}

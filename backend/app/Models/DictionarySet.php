<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'status'])]
class DictionarySet extends Model
{
    public function items(): HasMany
    {
        return $this->hasMany(DictionaryItem::class)->orderBy('sort_order')->orderBy('id');
    }
}

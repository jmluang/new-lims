<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dictionary_set_id', 'label', 'value', 'color', 'sort_order', 'is_default', 'status'])]
class DictionaryItem extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function dictionarySet(): BelongsTo
    {
        return $this->belongsTo(DictionarySet::class);
    }
}

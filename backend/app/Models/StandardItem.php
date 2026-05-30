<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'standard_id',
    'item_no',
    'item_name',
    'requirement',
    'unit',
    'method',
    'remark',
    'operator_id',
])]
class StandardItem extends Model
{
    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }
}

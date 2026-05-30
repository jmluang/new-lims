<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sample_id',
    'action_type',
    'action_by',
    'action_time',
    'holder_from',
    'holder_to',
    'location_from',
    'location_to',
    'remark',
])]
class SampleFlow extends Model
{
    protected function casts(): array
    {
        return [
            'action_time' => 'datetime',
        ];
    }

    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }
}

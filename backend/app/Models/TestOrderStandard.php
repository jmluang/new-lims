<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'test_order_id',
    'standard_id',
    'standard_code',
    'standard_name',
    'report_language',
    'qualifications',
    'requirement',
    'sort_order',
])]
class TestOrderStandard extends Model
{
    protected function casts(): array
    {
        return [
            'qualifications' => 'array',
        ];
    }

    public function testOrder(): BelongsTo
    {
        return $this->belongsTo(TestOrder::class);
    }

    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'test_order_id',
    'sample_name',
    'specification',
    'model',
    'input_voltage',
    'rated_current',
    'power',
    'rated_frequency',
    'status',
    'quantity',
    'quantity_unit',
    'sample_condition',
    'sample_condition_note',
    'detail_content',
    'remark',
    'sort_order',
])]
class TestOrderSample extends Model
{
    public function testOrder(): BelongsTo
    {
        return $this->belongsTo(TestOrder::class);
    }
}

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
    'status',
    'quantity',
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

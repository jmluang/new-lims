<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'test_order_id',
    'test_order_sample_id',
    'delivery_sequence',
    'sample_no',
    'sample_name',
    'specification',
    'model',
    'input_voltage',
    'rated_current',
    'power',
    'rated_frequency',
    'quantity',
    'status',
    'current_holder',
    'current_location',
    'storage_condition',
    'received_date',
    'appearance_check',
    'remark',
    'batch_no',
    'sort_order',
    'delivery_received_count',
    'created_by',
    'updated_by',
])]
class Sample extends Model
{
    protected function casts(): array
    {
        return [
            'received_date' => 'date',
        ];
    }

    public function testOrder(): BelongsTo
    {
        return $this->belongsTo(TestOrder::class);
    }

    public function orderSample(): BelongsTo
    {
        return $this->belongsTo(TestOrderSample::class, 'test_order_sample_id');
    }

    public function flows(): HasMany
    {
        return $this->hasMany(SampleFlow::class)->orderBy('action_time')->orderBy('id');
    }
}

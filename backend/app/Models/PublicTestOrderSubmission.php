<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'submission_no',
    'matched_customer_id',
    'test_order_id',
    'client_company',
    'client_address',
    'client_contact',
    'client_phone',
    'samples',
    'status',
    'review_remark',
    'submitted_ip',
    'submitted_user_agent',
    'submitted_at',
    'accepted_by',
    'accepted_at',
    'rejected_by',
    'rejected_at',
])]
class PublicTestOrderSubmission extends Model
{
    protected function casts(): array
    {
        return [
            'samples' => 'array',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function matchedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'matched_customer_id');
    }

    public function testOrder(): BelongsTo
    {
        return $this->belongsTo(TestOrder::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}

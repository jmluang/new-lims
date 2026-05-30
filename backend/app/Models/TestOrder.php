<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_no',
    'contract_no',
    'order_date',
    'planned_end_date',
    'urgency',
    'client_customer_id',
    'client_company',
    'client_address',
    'client_contact',
    'client_phone',
    'manufacturer_customer_id',
    'manufacturer_company',
    'manufacturer_address',
    'manufacturer_contact',
    'manufacturer_phone',
    'maker_customer_id',
    'maker_company',
    'maker_address',
    'maker_contact',
    'maker_phone',
    'report_forms',
    'delivery_method',
    'outsourcing_option',
    'remark',
    'sample_status',
    'address_lab_name',
    'address_contact',
    'address_detail',
    'address_phone',
    'client_signature',
    'client_sign_date',
    'dept_confirm',
    'dept_confirm_date',
    'lab_confirm',
    'lab_confirm_date',
    'created_by',
    'updated_by',
])]
class TestOrder extends Model
{
    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'planned_end_date' => 'date',
            'report_forms' => 'array',
            'client_sign_date' => 'date',
            'dept_confirm_date' => 'date',
            'lab_confirm_date' => 'date',
        ];
    }

    public function standards(): HasMany
    {
        return $this->hasMany(TestOrderStandard::class)->orderBy('sort_order')->orderBy('id');
    }

    public function samples(): HasMany
    {
        return $this->hasMany(TestOrderSample::class)->orderBy('sort_order')->orderBy('id');
    }

    public function clientCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_customer_id');
    }
}

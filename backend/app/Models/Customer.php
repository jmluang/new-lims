<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'credit_code',
    'type',
    'level',
    'source',
    'industry',
    'phone',
    'email',
    'address',
    'remark',
    'status',
])]
class Customer extends Model
{
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)->orderByDesc('is_default')->orderBy('id');
    }
}

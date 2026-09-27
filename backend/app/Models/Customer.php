<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'credit_code',
    'phone',
    'email',
    'address',
    'remark',
    'status',
])]
class Customer extends Model
{
    public function scopeMatchingPhone(Builder $query, string $phone): Builder
    {
        return $query->where('status', 'active')
            ->where(fn (Builder $customerQuery): Builder => $customerQuery
                ->where('phone', $phone)
                ->orWhereHas('contacts', fn (Builder $contactQuery): Builder => $contactQuery
                    ->where('status', 'active')
                    ->where('phone', $phone)));
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)->orderByDesc('is_default')->orderBy('id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'request_id',
    'actor_user_id',
    'actor_name_snapshot',
    'action',
    'module',
    'subject_type',
    'subject_id',
    'before_values',
    'after_values',
    'changed_values',
    'ip_address',
    'user_agent',
    'prev_hash',
    'hash',
    'created_at',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
            'changed_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }
}

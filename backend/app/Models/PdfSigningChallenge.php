<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfSigningChallenge extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'password_changed_at_snapshot' => 'datetime',
            'reauthenticated_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}

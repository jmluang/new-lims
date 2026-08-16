<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfJavaSigningExecution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'execution_started_at' => 'datetime',
            'private_key_started_at' => 'datetime',
            'execution_deadline_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'retry_exhausted_at' => 'datetime',
            'completed_at' => 'datetime',
            'terminal_at' => 'datetime',
            'result_last_verified_at' => 'datetime',
            'retention_until' => 'datetime',
            'retirement_purge_not_before' => 'datetime',
            'legal_hold_until' => 'datetime',
            'bytes_deleted_at' => 'datetime',
        ];
    }
}

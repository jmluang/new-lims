<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfOperationOutbox extends Model
{
    protected $table = 'pdf_operation_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'available_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }
}

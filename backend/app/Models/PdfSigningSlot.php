<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfSigningSlot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'normalized_rect' => 'array',
            'prepared_appearance_object_refs' => 'array',
        ];
    }
}

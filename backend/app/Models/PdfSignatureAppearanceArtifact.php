<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PdfSignatureAppearanceArtifact extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'slot_manifest' => 'array',
            'crop_box' => 'array',
            'retention_until' => 'datetime',
            'legal_hold_until' => 'datetime',
            'hold_started_at' => 'datetime',
            'hold_released_at' => 'datetime',
            'retirement_staged_at' => 'datetime',
            'retirement_purge_not_before' => 'datetime',
        ];
    }
}

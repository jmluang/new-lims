<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfDocument extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'document_uuid';
    }

    protected function casts(): array
    {
        return [
            'publication_version' => 'integer',
            'integrity_version' => 'integer',
            'integrity_hold_mask' => 'integer',
            'next_revision_number' => 'integer',
            'integrity_hold_started_at' => 'datetime',
            'integrity_hold_released_at' => 'datetime',
            'evidence_hold_mask' => 'integer',
            'evidence_hold_started_at' => 'datetime',
            'evidence_hold_released_at' => 'datetime',
            'legal_hold_until' => 'datetime',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PdfFile::class, 'document_id');
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(PdfSigningWorkflow::class, 'document_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(PdfFile::class, 'published_revision_id');
    }
}

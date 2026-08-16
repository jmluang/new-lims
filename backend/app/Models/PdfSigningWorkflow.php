<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfSigningWorkflow extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'workflow_uuid';
    }

    protected function casts(): array
    {
        return [
            'placement_plan' => 'array',
            'workflow_generation' => 'integer',
            'expected_publication_version' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(PdfDocument::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PdfSigningRequest::class, 'workflow_id')->orderBy('sequence');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(PdfSigningField::class, 'workflow_id');
    }
}

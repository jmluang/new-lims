<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PdfSigningRequest extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'request_uuid';
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(PdfSigningWorkflow::class);
    }

    public function act(): BelongsTo
    {
        return $this->belongsTo(PdfSigningAct::class, 'signing_act_id');
    }

    public function field(): HasOne
    {
        return $this->hasOne(PdfSigningField::class, 'request_id');
    }

    public function appearances(): HasMany
    {
        return $this->hasMany(PdfSignatureAppearanceArtifact::class, 'request_id');
    }
}

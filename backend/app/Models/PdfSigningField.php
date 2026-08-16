<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdfSigningField extends Model
{
    protected $guarded = [];

    public function slots(): HasMany
    {
        return $this->hasMany(PdfSigningSlot::class, 'field_id')->orderBy('widget_index');
    }
}

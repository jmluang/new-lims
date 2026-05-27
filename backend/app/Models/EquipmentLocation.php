<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'name', 'code', 'sort_order', 'status'])]
class EquipmentLocation extends Model
{
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'location_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'status'])]
class EquipmentSystem extends Model
{
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'system_id');
    }
}

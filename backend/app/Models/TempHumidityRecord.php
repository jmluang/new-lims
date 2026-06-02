<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'equip_no',
    'temperature',
    'humidity',
    'location_site',
    'location_room',
    'record_person',
    'remark',
    'record_time',
])]
class TempHumidityRecord extends Model
{
    protected $table = 'temp_humidity_records';

    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:2',
            'humidity' => 'decimal:2',
            'record_time' => 'datetime',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equip_no', 'equipment_no');
    }
}

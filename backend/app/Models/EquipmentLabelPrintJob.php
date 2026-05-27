<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['equipment_ids', 'label_width_mm', 'label_height_mm', 'created_by'])]
class EquipmentLabelPrintJob extends Model
{
    protected function casts(): array
    {
        return [
            'equipment_ids' => 'array',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'project_no',
    'project_name',
    'status',
    'sort_order',
    'remark',
])]
class CalibrationProject extends Model
{
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}

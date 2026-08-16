<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PdfSourceUpload extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'source_uuid';
    }

    protected function casts(): array
    {
        return [
            'inspection_manifest' => 'array',
            'file_size' => 'integer',
            'page_count' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}

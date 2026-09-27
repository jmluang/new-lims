<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PdfYanzhenjiaSync extends Model
{
    protected $fillable = ['pdf_file_id', 'status', 'remote_file_id', 'attempts', 'last_error', 'synced_at'];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    public function pdfFile(): BelongsTo
    {
        return $this->belongsTo(PdfFile::class);
    }
}

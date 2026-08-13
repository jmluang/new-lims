<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'file_name',
    'file_size',
    'primary_hash',
    'secondary_hash',
    'md5_hash',
    'crc32_hash',
    'overall_valid',
    'security_level',
    'verification_message',
    'verification_data',
    'verify_source',
    'ip_address',
    'user_agent',
    'saved_file_path',
])]
class PdfVerificationLog extends Model
{
    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_PUBLIC = 'public';

    protected function casts(): array
    {
        return [
            'overall_valid' => 'boolean',
            'verification_data' => 'array',
            'file_size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

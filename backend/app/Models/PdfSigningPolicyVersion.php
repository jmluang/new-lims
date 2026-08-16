<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfSigningPolicyVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'immutable_at' => 'datetime',
            'organization_certificate_fingerprints' => 'array',
            'tsa_url_set' => 'array',
            'revocation_policy' => 'array',
            'pre_private_key_retry_backoff_seconds' => 'array',
            'pre_private_key_retryable_error_codes' => 'array',
            'java_status_poll_policy' => 'array',
            'policy_manifest' => 'array',
        ];
    }
}

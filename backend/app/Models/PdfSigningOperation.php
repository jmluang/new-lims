<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfSigningOperation extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'operation_uuid';
    }

    protected function casts(): array
    {
        return [
            'audit_context' => 'array',
            'document_evidence_hold_mask' => 'integer',
            'result_retirement_authorization_manifest' => 'array',
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'java_request_started_at' => 'datetime',
            'java_execution_registration_deadline_at' => 'datetime',
            'java_execution_deadline_at' => 'datetime',
            'next_java_poll_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'result_retirement_not_before' => 'datetime',
            'result_retirement_authorized_at' => 'datetime',
            'result_retirement_authorization_expires_at' => 'datetime',
        ];
    }
}

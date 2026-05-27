<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function authorizePermission(Request $request, string $permission, ?string $module = null, mixed $subject = null): void
    {
        if ($request->user()?->hasRole('super_admin') || $request->user()?->can($permission)) {
            return;
        }

        if ($module !== null) {
            app(AuditLogger::class)->record(
                actor: $request->user(),
                action: 'authorization.denied',
                module: $module,
                subject: $subject,
                after: ['permission' => $permission, 'path' => $request->path()],
            );
        }

        abort(403);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless(
            $request->user()?->hasRole('super_admin') || $request->user()?->can($permission),
            403
        );
    }
}

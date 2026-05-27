<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChangeIsNotRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'error' => 'password_change_required',
                'message' => 'Password must be changed before accessing this API.',
            ], 409);
        }

        return $next($request);
    }
}

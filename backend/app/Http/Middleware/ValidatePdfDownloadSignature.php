<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpFoundation\Response;

class ValidatePdfDownloadSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        // New links are scheme-independent; retain unexpired absolute links
        // issued before this change. Both checks include HMAC and expiration.
        if (! $request->hasValidRelativeSignature() && ! $request->hasValidSignature()) {
            throw new InvalidSignatureException;
        }

        return $next($request);
    }
}

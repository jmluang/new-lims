<?php

namespace App\Services\Pdf;

use Illuminate\Http\Request;

final class PdfRequestContext
{
    public static function authContextId(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        $tokenKey = is_object($token) && method_exists($token, 'getKey') ? (string) $token->getKey() : 'transient';

        return hash('sha256', implode(':', [
            'sanctum',
            $request->user()->id,
            $tokenKey,
            hash('sha256', (string) $request->bearerToken()),
        ]));
    }

    /** @return array<string, string|int|null> */
    public static function auditContext(Request $request): array
    {
        return [
            'actor_user_id' => $request->user()->id,
            'auth_context_id' => self::authContextId($request),
            'request_id' => $request->attributes->get('request_id'),
            'ip' => $request->ip(),
            'user_agent_sha256' => hash('sha256', (string) $request->userAgent()),
        ];
    }
}

<?php

namespace App\Services\TestOrders;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class VerifiedMiniAppPhone
{
    private const USER_ENDPOINT = 'https://www.yanzhenjia.cn/api/user';

    public function fromToken(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout(5)
                ->get(self::USER_ENDPOINT);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->ok() || $response->json('success') !== true) {
            return null;
        }

        $phone = $response->json('data.user.phone');

        return is_string($phone) && preg_match('/^1[3-9]\d{9}$/', $phone) ? $phone : null;
    }
}

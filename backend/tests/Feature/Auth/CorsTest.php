<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_local_vite_dev_ports_are_allowed_for_api_preflight_requests(): void
    {
        config()->set('cors.allowed_origins', ['http://127.0.0.1:5174']);

        $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5174',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/me')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5174');
    }
}

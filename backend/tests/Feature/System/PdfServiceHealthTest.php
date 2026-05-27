<?php

namespace Tests\Feature\System;

use App\Models\User;
use App\Services\Pdf\PdfRendererClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PdfServiceHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_service_health_returns_healthy_when_client_is_healthy(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $client = Mockery::mock(PdfRendererClient::class);
        $client->shouldReceive('health')->once()->andReturnTrue();
        $this->app->instance(PdfRendererClient::class, $client);

        $this->getJson('/api/system/pdf-service/health')
            ->assertOk()
            ->assertJsonPath('data.healthy', true);
    }

    public function test_pdf_service_health_returns_unavailable_when_disabled(): void
    {
        config(['pdf_service.enabled' => false]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/system/pdf-service/health')
            ->assertStatus(503)
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.message', 'PDF service is disabled.');
    }

    public function test_pdf_service_health_returns_unavailable_when_client_fails(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $client = Mockery::mock(PdfRendererClient::class);
        $client->shouldReceive('health')->once()->andThrow(new RuntimeException('PDF service request failed.'));
        $this->app->instance(PdfRendererClient::class, $client);

        $this->getJson('/api/system/pdf-service/health')
            ->assertStatus(503)
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.message', 'PDF service request failed.');
    }
}

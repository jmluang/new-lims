<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Services\Audit\AuditHashService;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logger_records_before_after_changed_values_and_hash_chain(): void
    {
        $actor = User::factory()->create(['name' => 'Operator']);
        $subject = User::factory()->create(['name' => 'Old Name']);

        $firstLog = app(AuditLogger::class)->record(
            actor: $actor,
            action: 'system.users.update',
            module: 'system.users',
            subject: $subject,
            before: ['name' => 'Old Name', 'email' => 'old@example.test'],
            after: ['name' => 'New Name', 'email' => 'old@example.test'],
            requestMeta: [
                'request_id' => 'req-audit-1',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Audit Test',
            ],
        );

        $secondLog = app(AuditLogger::class)->record(
            actor: $actor,
            action: 'system.users.lock',
            module: 'system.users',
            subject: $subject,
            before: ['status' => 'active'],
            after: ['status' => 'locked'],
            requestMeta: [
                'request_id' => 'req-audit-2',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Audit Test',
            ],
        );

        $this->assertSame(['name' => ['old' => 'Old Name', 'new' => 'New Name']], $firstLog->changed_values);
        $this->assertSame($actor->id, $firstLog->actor_user_id);
        $this->assertSame('Operator', $firstLog->actor_name_snapshot);
        $this->assertSame($subject::class, $firstLog->subject_type);
        $this->assertSame((string) $subject->getKey(), $firstLog->subject_id);
        $this->assertSame('127.0.0.1', $firstLog->ip_address);
        $this->assertSame('Audit Test', $firstLog->user_agent);
        $this->assertNull($firstLog->prev_hash);
        $this->assertNotEmpty($firstLog->hash);
        $this->assertSame($firstLog->hash, $secondLog->prev_hash);
        $this->assertTrue(app(AuditHashService::class)->verifyChain());
    }

    public function test_audit_log_model_is_append_only_for_application_code(): void
    {
        $log = app(AuditLogger::class)->record(
            actor: User::factory()->create(),
            action: 'system.users.create',
            module: 'system.users',
            requestMeta: ['request_id' => 'append-only-test'],
        );

        $this->assertFalse($log->update(['action' => 'tampered']));
        $this->assertFalse($log->delete());
        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'system.users.create',
        ]);
    }

    public function test_hash_chain_verification_fails_after_direct_database_tampering(): void
    {
        $log = app(AuditLogger::class)->record(
            actor: User::factory()->create(),
            action: 'system.users.create',
            module: 'system.users',
            after: ['name' => 'Created User'],
            requestMeta: ['request_id' => 'tamper-test'],
        );

        $this->assertTrue(app(AuditHashService::class)->verifyChain());

        DB::table('audit_logs')
            ->where('id', $log->id)
            ->update(['after_values' => json_encode(['name' => 'Tampered User'])]);

        $this->assertFalse(app(AuditHashService::class)->verifyChain());
    }

    public function test_hash_chain_verification_fails_after_direct_database_middle_row_deletion(): void
    {
        app(AuditLogger::class)->record(
            actor: User::factory()->create(),
            action: 'system.users.create',
            module: 'system.users',
            requestMeta: ['request_id' => 'delete-test-1'],
        );
        $middleLog = app(AuditLogger::class)->record(
            actor: User::factory()->create(),
            action: 'system.users.update',
            module: 'system.users',
            requestMeta: ['request_id' => 'delete-test-2'],
        );
        app(AuditLogger::class)->record(
            actor: User::factory()->create(),
            action: 'system.users.lock',
            module: 'system.users',
            requestMeta: ['request_id' => 'delete-test-3'],
        );

        $this->assertTrue(app(AuditHashService::class)->verifyChain());

        DB::table('audit_logs')
            ->where('id', $middleLog->id)
            ->delete();

        $this->assertFalse(app(AuditHashService::class)->verifyChain());
    }

    public function test_api_requests_receive_a_request_id_header_and_attribute(): void
    {
        Route::middleware('api')->get('/audit-request-id-test', fn () => response()->json([
            'request_id' => request()->attributes->get('request_id'),
        ]));

        $this->withHeader('X-Request-Id', 'client-request-id')
            ->getJson('/audit-request-id-test')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'client-request-id')
            ->assertJsonPath('request_id', 'client-request-id');
    }

    public function test_api_requests_generate_a_request_id_when_header_is_missing(): void
    {
        Route::middleware('api')->get('/audit-generated-request-id-test', fn () => response()->json([
            'request_id' => request()->attributes->get('request_id'),
        ]));

        $response = $this->getJson('/audit-generated-request-id-test')
            ->assertOk()
            ->assertHeader('X-Request-Id');

        $this->assertSame(
            $response->headers->get('X-Request-Id'),
            $response->json('request_id'),
        );
    }
}

<?php

namespace Tests\Unit\Audit;

use App\Services\Audit\AuditHashService;
use PHPUnit\Framework\TestCase;

class AuditHashServiceTest extends TestCase
{
    public function test_hash_is_stable_for_equivalent_json_with_different_key_order(): void
    {
        $hashService = new AuditHashService;

        $firstHash = $hashService->calculate(
            prevHash: 'previous',
            requestId: 'request-1',
            actorUserId: 1,
            action: 'customers.update',
            module: 'customers',
            subjectType: 'customers',
            subjectId: '10',
            beforeValues: ['name' => 'Old', 'meta' => ['b' => 2, 'a' => 1]],
            afterValues: ['meta' => ['a' => 1, 'b' => 3], 'name' => 'New'],
            changedValues: ['meta' => ['new' => ['b' => 3, 'a' => 1], 'old' => ['b' => 2, 'a' => 1]], 'name' => ['old' => 'Old', 'new' => 'New']],
            createdAt: '2026-05-27 10:00:00',
        );

        $secondHash = $hashService->calculate(
            prevHash: 'previous',
            requestId: 'request-1',
            actorUserId: 1,
            action: 'customers.update',
            module: 'customers',
            subjectType: 'customers',
            subjectId: '10',
            beforeValues: ['meta' => ['a' => 1, 'b' => 2], 'name' => 'Old'],
            afterValues: ['name' => 'New', 'meta' => ['b' => 3, 'a' => 1]],
            changedValues: ['name' => ['new' => 'New', 'old' => 'Old'], 'meta' => ['old' => ['a' => 1, 'b' => 2], 'new' => ['a' => 1, 'b' => 3]]],
            createdAt: '2026-05-27 10:00:00',
        );

        $this->assertSame($firstHash, $secondHash);
    }
}

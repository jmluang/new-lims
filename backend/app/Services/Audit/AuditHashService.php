<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use DateTimeInterface;

class AuditHashService
{
    /**
     * @param  array<string, mixed>|null  $beforeValues
     * @param  array<string, mixed>|null  $afterValues
     * @param  array<string, mixed>|null  $changedValues
     */
    public function calculate(
        ?string $prevHash,
        string $requestId,
        ?int $actorUserId,
        string $action,
        string $module,
        ?string $subjectType,
        ?string $subjectId,
        ?array $beforeValues,
        ?array $afterValues,
        ?array $changedValues,
        DateTimeInterface|string $createdAt,
    ): string {
        return hash('sha256', implode('|', [
            $prevHash ?? '',
            $requestId,
            (string) ($actorUserId ?? ''),
            $action,
            $module,
            $subjectType ?? '',
            $subjectId ?? '',
            $this->canonicalJson($beforeValues),
            $this->canonicalJson($afterValues),
            $this->canonicalJson($changedValues),
            $this->timestamp($createdAt),
        ]));
    }

    public function verifyChain(): bool
    {
        $previousHash = null;

        foreach (AuditLog::query()->orderBy('id')->cursor() as $auditLog) {
            if ($auditLog->prev_hash !== $previousHash) {
                return false;
            }

            $expectedHash = $this->calculate(
                prevHash: $auditLog->prev_hash,
                requestId: $auditLog->request_id,
                actorUserId: $auditLog->actor_user_id,
                action: $auditLog->action,
                module: $auditLog->module,
                subjectType: $auditLog->subject_type,
                subjectId: $auditLog->subject_id,
                beforeValues: $auditLog->before_values,
                afterValues: $auditLog->after_values,
                changedValues: $auditLog->changed_values,
                createdAt: $auditLog->created_at,
            );

            if (! hash_equals($expectedHash, $auditLog->hash)) {
                return false;
            }

            $previousHash = $auditLog->hash;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function canonicalJson(?array $value): string
    {
        if ($value === null) {
            return '';
        }

        $this->sortKeys($value);

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function sortKeys(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortKeys($item);
            }
        }
    }

    private function timestamp(DateTimeInterface|string $createdAt): string
    {
        if ($createdAt instanceof DateTimeInterface) {
            return $createdAt->format('Y-m-d H:i:s');
        }

        return $createdAt;
    }
}

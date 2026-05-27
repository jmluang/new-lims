<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private readonly AuditHashService $auditHashService) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array{request_id?: string, ip_address?: string, user_agent?: string}  $requestMeta
     */
    public function record(
        ?User $actor,
        string $action,
        string $module,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        array $requestMeta = [],
    ): AuditLog {
        return DB::transaction(function () use ($actor, $action, $module, $subject, $before, $after, $requestMeta): AuditLog {
            $createdAt = Carbon::now()->setMicrosecond(0);
            $beforeValues = $before === [] ? null : $before;
            $afterValues = $after === [] ? null : $after;
            $changedValues = $this->changedValues($before, $after);
            $changedValues = $changedValues === [] ? null : $changedValues;
            $requestId = $requestMeta['request_id']
                ?? request()?->attributes->get('request_id')
                ?? request()?->headers->get('X-Request-Id')
                ?? (string) Str::uuid();
            $prevHash = AuditLog::query()
                ->lockForUpdate()
                ->latest('id')
                ->value('hash');
            $subjectId = $subject === null ? null : (string) $subject->getKey();

            $hash = $this->auditHashService->calculate(
                prevHash: $prevHash,
                requestId: $requestId,
                actorUserId: $actor?->id,
                action: $action,
                module: $module,
                subjectType: $subject?->getMorphClass(),
                subjectId: $subjectId,
                beforeValues: $beforeValues,
                afterValues: $afterValues,
                changedValues: $changedValues,
                createdAt: $createdAt,
            );

            return AuditLog::query()->create([
                'request_id' => $requestId,
                'actor_user_id' => $actor?->id,
                'actor_name_snapshot' => $actor?->name,
                'action' => $action,
                'module' => $module,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subjectId,
                'before_values' => $beforeValues,
                'after_values' => $afterValues,
                'changed_values' => $changedValues,
                'ip_address' => $requestMeta['ip_address'] ?? request()?->ip(),
                'user_agent' => $requestMeta['user_agent'] ?? request()?->userAgent(),
                'prev_hash' => $prevHash,
                'hash' => $hash,
                'created_at' => $createdAt,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(array $before, array $after): array
    {
        $changed = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $oldValue = $before[$key] ?? null;
            $newValue = $after[$key] ?? null;

            if ($oldValue !== $newValue) {
                $changed[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changed;
    }
}

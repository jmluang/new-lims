<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    private const RESOURCE = 'system.audit_logs';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'system.audit_logs.read', self::RESOURCE);

        $auditLogs = $this->filteredQuery($request)
            ->latest('id')
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $auditLogs->getCollection()->map(fn (AuditLog $auditLog): array => $this->serialize($auditLog))->values(),
            'meta' => [
                'current_page' => $auditLogs->currentPage(),
                'per_page' => $auditLogs->perPage(),
                'total' => $auditLogs->total(),
            ],
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): JsonResponse
    {
        $this->authorizePermission($request, 'system.audit_logs.read', self::RESOURCE);

        return response()->json(['data' => $this->serialize($auditLog)]);
    }

    public function export(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.audit_logs.export', self::RESOURCE);

        $headers = [
            'id',
            'created_at',
            'request_id',
            'actor_user_id',
            'actor_name_snapshot',
            'module',
            'action',
            'subject_type',
            'subject_id',
            'before_values',
            'after_values',
            'changed_values',
            'ip_address',
            'user_agent',
            'prev_hash',
            'hash',
        ];
        $rows = $this->filteredQuery($request)
            ->oldest('id')
            ->limit(5000)
            ->get()
            ->map(fn (AuditLog $auditLog): array => collect($this->serialize($auditLog))
                ->only($headers)
                ->all())
            ->values();

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.audit_logs.export',
            module: self::RESOURCE,
            after: [
                'filters' => $request->query(),
                'columns' => $headers,
                'row_count' => $rows->count(),
            ],
        );

        return response()->json([
            'headers' => $headers,
            'data' => $rows,
        ]);
    }

    /**
     * @return Builder<AuditLog>
     */
    private function filteredQuery(Request $request): Builder
    {
        return AuditLog::query()
            ->when($request->filled('module'), fn (Builder $query): Builder => $query->where('module', $request->string('module')->toString()))
            ->when($request->filled('action'), fn (Builder $query): Builder => $query->where('action', $request->string('action')->toString()))
            ->when($request->filled('request_id'), fn (Builder $query): Builder => $query->where('request_id', $request->string('request_id')->toString()))
            ->when($request->filled('actor_user_id'), fn (Builder $query): Builder => $query->where('actor_user_id', $request->integer('actor_user_id')))
            ->when($request->filled('actor'), function (Builder $query) use ($request): Builder {
                $actor = $request->string('actor')->toString();

                return $query->where(function (Builder $query) use ($actor): void {
                    $query->where('actor_name_snapshot', 'like', "%{$actor}%");

                    if (ctype_digit($actor)) {
                        $query->orWhere('actor_user_id', (int) $actor);
                    }
                });
            })
            ->when($request->filled('subject_type'), fn (Builder $query): Builder => $query->where('subject_type', $request->string('subject_type')->toString()))
            ->when($request->filled('subject_id'), fn (Builder $query): Builder => $query->where('subject_id', $request->string('subject_id')->toString()))
            ->when($request->filled('date_from'), fn (Builder $query): Builder => $query->where('created_at', '>=', $request->date('date_from')?->startOfDay()))
            ->when($request->filled('date_to'), fn (Builder $query): Builder => $query->where('created_at', '<=', $request->date('date_to')?->endOfDay()));
    }

    private function serialize(AuditLog $auditLog): array
    {
        return [
            'id' => $auditLog->id,
            'created_at' => $auditLog->created_at?->toJSON(),
            'request_id' => $auditLog->request_id,
            'actor_user_id' => $auditLog->actor_user_id,
            'actor_name_snapshot' => $auditLog->actor_name_snapshot,
            'module' => $auditLog->module,
            'action' => $auditLog->action,
            'subject_type' => $auditLog->subject_type,
            'subject_id' => $auditLog->subject_id,
            'before_values' => $auditLog->before_values,
            'after_values' => $auditLog->after_values,
            'changed_values' => $auditLog->changed_values,
            'ip_address' => $auditLog->ip_address,
            'user_agent' => $auditLog->user_agent,
            'prev_hash' => $auditLog->prev_hash,
            'hash' => $auditLog->hash,
        ];
    }
}

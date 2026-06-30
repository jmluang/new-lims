<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Jobs\RunSystemBackupJob;
use App\Models\BackupRun;
use App\Services\Audit\AuditLogger;
use App\Services\System\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

class BackupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'system.backups.read');
        $backups = BackupRun::query()->latest('id')->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $backups->getCollection(),
            'meta' => [
                'current_page' => $backups->currentPage(),
                'per_page' => $backups->perPage(),
                'total' => $backups->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'system.backups.create');

        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:32'],
        ]);

        $backupRun = BackupRun::query()->create([
            'type' => $data['type'] ?? 'manual',
            'status' => 'pending',
        ]);

        try {
            Queue::connection((string) config('backup.backup.job.connection', 'backups'))->pushOn(
                (string) config('backup.backup.job.queue', 'backups'),
                new RunSystemBackupJob($backupRun->id),
            );
        } catch (Throwable $throwable) {
            $backupRun->update([
                'status' => 'failed',
                'error_message' => $throwable->getMessage(),
                'finished_at' => Carbon::now(),
            ]);

            throw $throwable;
        }

        return response()->json([
            'data' => $backupRun->fresh(),
        ], 202);
    }

    public function restore(Request $request, BackupRun $backupRun, BackupService $backupService, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.backups.restore', 'system.backups', $backupRun);

        try {
            $result = $backupService->restore($backupRun);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.backups.restore',
            module: 'system.backups',
            subject: $backupRun,
            after: $result,
        );

        return response()->json([
            'data' => $result,
        ]);
    }
}

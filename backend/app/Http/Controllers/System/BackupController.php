<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Services\Audit\AuditLogger;
use App\Services\System\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

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

        Artisan::call('lims:backup', ['--type' => $data['type'] ?? 'manual']);

        return response()->json([
            'data' => BackupRun::query()->latest('id')->first(),
        ], 201);
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

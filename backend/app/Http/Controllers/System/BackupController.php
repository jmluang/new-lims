<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class BackupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'system.backups.read');

        return response()->json([
            'data' => BackupRun::query()->latest('id')->get(),
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
}

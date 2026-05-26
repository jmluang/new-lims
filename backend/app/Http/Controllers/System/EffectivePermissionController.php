<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Authorization\EffectivePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EffectivePermissionController extends Controller
{
    public function __invoke(Request $request, EffectivePermissionService $effectivePermissionService): JsonResponse
    {
        return response()->json([
            'data' => $effectivePermissionService->forUser($request->user())->toArray(),
        ]);
    }
}

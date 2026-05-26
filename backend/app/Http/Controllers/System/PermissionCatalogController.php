<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Authorization\PermissionCatalog;
use Illuminate\Http\JsonResponse;

class PermissionCatalogController extends Controller
{
    public function __invoke(PermissionCatalog $permissionCatalog): JsonResponse
    {
        return response()->json([
            'data' => $permissionCatalog->toArray(),
        ]);
    }
}

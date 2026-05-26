<?php

use App\Http\Controllers\System\EffectivePermissionController;
use App\Http\Controllers\System\PermissionCatalogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', function (Request $request) {
        return response()->json([
            'data' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    });

    Route::get('/permissions/effective', EffectivePermissionController::class);
    Route::get('/system/permissions/catalog', PermissionCatalogController::class);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

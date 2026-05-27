<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CustomerContactController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\EquipmentLabelController;
use App\Http\Controllers\EquipmentLocationController;
use App\Http\Controllers\System\BackupController;
use App\Http\Controllers\System\DepartmentController;
use App\Http\Controllers\System\DictionaryController;
use App\Http\Controllers\System\EffectivePermissionController;
use App\Http\Controllers\System\GroupController;
use App\Http\Controllers\System\PdfServiceHealthController;
use App\Http\Controllers\System\PermissionCatalogController;
use App\Http\Controllers\System\UserController;
use App\Http\Middleware\EnsurePasswordChangeIsNotRequired;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [LoginController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', function (Request $request) {
        return response()->json([
            'data' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'must_change_password' => $request->user()->must_change_password,
            ],
        ]);
    });

    Route::get('/permissions/effective', EffectivePermissionController::class);
    Route::post('/auth/password', [LoginController::class, 'changePassword']);
    Route::post('/logout', [LoginController::class, 'logout']);

    Route::middleware(EnsurePasswordChangeIsNotRequired::class)->group(function (): void {
        Route::get('/system/permissions/catalog', PermissionCatalogController::class);
        Route::get('/dictionary-options', [DictionaryController::class, 'options']);

        Route::apiResource('/system/users', UserController::class)->except(['destroy']);
        Route::post('/system/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('/system/users/{user}/lock', [UserController::class, 'lock']);
        Route::post('/system/users/{user}/unlock', [UserController::class, 'unlock']);

        Route::apiResource('/system/departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('/system/groups', GroupController::class)->parameters(['groups' => 'group'])->only(['index', 'store', 'show', 'update']);
        Route::put('/system/groups/{group}/permissions', [GroupController::class, 'syncPermissionMatrix']);

        Route::get('/dictionaries', [DictionaryController::class, 'index']);
        Route::post('/dictionaries', [DictionaryController::class, 'store']);
        Route::put('/dictionaries/{dictionarySet}', [DictionaryController::class, 'update']);
        Route::post('/dictionaries/{dictionarySet}/items', [DictionaryController::class, 'storeItem']);
        Route::put('/dictionaries/{dictionarySet}/items/{dictionaryItem}', [DictionaryController::class, 'updateItem']);

        Route::get('/backups', [BackupController::class, 'index']);
        Route::post('/backups', [BackupController::class, 'store']);
        Route::post('/backups/{backupRun}/restore', [BackupController::class, 'restore']);
        Route::get('/system/pdf-service/health', PdfServiceHealthController::class);

        Route::get('/audit-logs/export', [AuditLogController::class, 'export']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);

        Route::get('/customers/export', [CustomerController::class, 'export']);
        Route::apiResource('/customers', CustomerController::class);
        Route::get('/customers/{customer}/contacts', [CustomerContactController::class, 'index']);
        Route::post('/customers/{customer}/contacts', [CustomerContactController::class, 'store']);
        Route::put('/customers/{customer}/contacts/{customerContact}', [CustomerContactController::class, 'update']);
        Route::delete('/customers/{customer}/contacts/{customerContact}', [CustomerContactController::class, 'destroy']);

        Route::apiResource('/equipment-locations', EquipmentLocationController::class)->parameters(['equipment-locations' => 'equipmentLocation'])->only(['index', 'store', 'update', 'destroy']);
        Route::get('/equipment/{equipment}/files/{field}/{index?}', [EquipmentController::class, 'downloadFile']);
        Route::apiResource('/equipment', EquipmentController::class);
        Route::post('/equipment-labels/preview', [EquipmentLabelController::class, 'preview']);
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

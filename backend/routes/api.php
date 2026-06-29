<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CalibrationProjectController;
use App\Http\Controllers\CalibrationProjectLabelController;
use App\Http\Controllers\CustomerContactController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\EquipmentCalibrationController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\EquipmentLabelController;
use App\Http\Controllers\EquipmentLocationController;
use App\Http\Controllers\EquipmentSystemController;
use App\Http\Controllers\EquipmentUsageRecordController;
use App\Http\Controllers\PublicTestOrderSubmissionController;
use App\Http\Controllers\PublicTestOrderSubmissionReviewController;
use App\Http\Controllers\SampleController;
use App\Http\Controllers\SampleFlowCardController;
use App\Http\Controllers\SampleFlowController;
use App\Http\Controllers\SampleLabelController;
use App\Http\Controllers\SampleScanController;
use App\Http\Controllers\StandardCatalogController;
use App\Http\Controllers\StandardController;
use App\Http\Controllers\StandardItemController;
use App\Http\Controllers\System\BackupController;
use App\Http\Controllers\System\DepartmentController;
use App\Http\Controllers\System\EffectivePermissionController;
use App\Http\Controllers\System\GroupController;
use App\Http\Controllers\System\PdfServiceHealthController;
use App\Http\Controllers\System\PermissionCatalogController;
use App\Http\Controllers\System\UserController;
use App\Http\Controllers\TempHumidityRecordController;
use App\Http\Controllers\TestOrderController;
use App\Http\Controllers\TestOrderEntrustOrderController;
use App\Http\Controllers\TestOrderMessageController;
use App\Http\Controllers\UserMessageController;
use App\Http\Middleware\EnsurePasswordChangeIsNotRequired;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [LoginController::class, 'login']);
Route::post('/register', [LoginController::class, 'register']);
Route::get('/register/options', [LoginController::class, 'registerOptions']);

// Public device ingest: temperature/humidity sensors push readings here
// (ported from the legacy example/post.php). Accepts GET or POST.
Route::match(['get', 'post'], '/device/temp-humidity', [TempHumidityRecordController::class, 'ingest']);
Route::post('/public/test-order-submissions/customer-lookup', [PublicTestOrderSubmissionController::class, 'lookupCustomer'])
    ->middleware('throttle:20,1');
Route::post('/public/test-order-submissions', [PublicTestOrderSubmissionController::class, 'store'])
    ->middleware('throttle:5,1');

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

        Route::apiResource('/system/users', UserController::class)->except(['destroy']);
        Route::post('/system/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('/system/users/{user}/lock', [UserController::class, 'lock']);
        Route::post('/system/users/{user}/unlock', [UserController::class, 'unlock']);

        Route::apiResource('/system/departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('/system/groups', GroupController::class)->parameters(['groups' => 'group'])->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::put('/system/groups/{group}/permissions', [GroupController::class, 'syncPermissionMatrix']);

        Route::get('/backups', [BackupController::class, 'index']);
        Route::post('/backups', [BackupController::class, 'store']);
        Route::post('/backups/{backupRun}/restore', [BackupController::class, 'restore']);
        Route::get('/system/pdf-service/health', PdfServiceHealthController::class);

        Route::get('/messages', [UserMessageController::class, 'index']);
        Route::post('/messages/{message}/read', [UserMessageController::class, 'markRead']);

        Route::get('/audit-logs/export', [AuditLogController::class, 'export']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show']);

        Route::get('/customers/export', [CustomerController::class, 'export']);
        Route::apiResource('/customers', CustomerController::class);
        Route::get('/customers/{customer}/contacts', [CustomerContactController::class, 'index']);
        Route::post('/customers/{customer}/contacts', [CustomerContactController::class, 'store']);
        Route::put('/customers/{customer}/contacts/{customerContact}', [CustomerContactController::class, 'update']);
        Route::delete('/customers/{customer}/contacts/{customerContact}', [CustomerContactController::class, 'destroy']);

        Route::get('/standards/export', [StandardController::class, 'export']);
        Route::apiResource('/standards', StandardController::class);
        Route::scopeBindings()->group(function (): void {
            Route::get('/standards/{standard}/catalogs', [StandardCatalogController::class, 'index']);
            Route::post('/standards/{standard}/catalogs', [StandardCatalogController::class, 'store']);
            Route::put('/standards/{standard}/catalogs/{standardCatalog}', [StandardCatalogController::class, 'update']);
            Route::delete('/standards/{standard}/catalogs/{standardCatalog}', [StandardCatalogController::class, 'destroy']);
            Route::get('/standards/{standard}/items', [StandardItemController::class, 'index']);
            Route::post('/standards/{standard}/items', [StandardItemController::class, 'store']);
            Route::put('/standards/{standard}/items/{standardItem}', [StandardItemController::class, 'update']);
            Route::delete('/standards/{standard}/items/{standardItem}', [StandardItemController::class, 'destroy']);
        });

        Route::get('/test-orders/export', [TestOrderController::class, 'export']);
        Route::get('/test-orders/form-options', [TestOrderController::class, 'formOptions']);
        Route::get('/test-orders/message-recipients', [TestOrderMessageController::class, 'recipients']);
        Route::post('/test-orders/{testOrder}/messages', [TestOrderMessageController::class, 'store']);
        Route::get('/test-orders/{testOrder}/entrust-order.pdf', [TestOrderEntrustOrderController::class, 'show']);
        Route::get('/test-orders/{testOrder}/sample-options', [TestOrderController::class, 'sampleOptions']);
        Route::apiResource('/test-orders', TestOrderController::class);
        Route::get('/public-test-order-submissions', [PublicTestOrderSubmissionReviewController::class, 'index']);
        Route::post('/public-test-order-submissions/{publicTestOrderSubmission}/accept', [PublicTestOrderSubmissionReviewController::class, 'accept']);
        Route::post('/public-test-order-submissions/{publicTestOrderSubmission}/reject', [PublicTestOrderSubmissionReviewController::class, 'reject']);

        Route::get('/samples', [SampleController::class, 'index']);
        Route::get('/samples/receive-options', [SampleController::class, 'receiveOptions']);
        Route::post('/samples/receive', [SampleController::class, 'receive']);
        // Literal route must precede /samples/{sample} so implicit model binding does not treat "scan-lookup" as a sample id.
        Route::get('/samples/scan-lookup', [SampleScanController::class, 'lookup']);
        Route::get('/samples/{sample}', [SampleController::class, 'show']);
        Route::get('/samples/{sample}/flows', [SampleFlowController::class, 'index']);
        Route::post('/samples/{sample}/flows', [SampleFlowController::class, 'store']);
        Route::get('/samples/{sample}/flow-card', [SampleFlowCardController::class, 'show']);
        Route::post('/samples/{sample}/scan-flow', [SampleScanController::class, 'store']);
        Route::post('/sample-labels/preview', [SampleLabelController::class, 'preview']);
        Route::get('/sample-flows', [SampleFlowController::class, 'globalIndex']);

        Route::apiResource('/equipment-locations', EquipmentLocationController::class)->parameters(['equipment-locations' => 'equipmentLocation'])->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('/equipment-systems', EquipmentSystemController::class)->parameters(['equipment-systems' => 'equipmentSystem'])->only(['index', 'store', 'update', 'destroy']);
        Route::get('/equipment-usage-records/form-options', [EquipmentUsageRecordController::class, 'formOptions']);
        Route::get('/equipment-usage-records/lookup', [EquipmentUsageRecordController::class, 'lookup']);
        Route::post('/equipment-usage-records/start', [EquipmentUsageRecordController::class, 'start']);
        Route::post('/equipment-usage-records/batch-end', [EquipmentUsageRecordController::class, 'batchEnd']);
        Route::post('/equipment-usage-records/{equipmentUsageRecord}/end', [EquipmentUsageRecordController::class, 'end']);
        Route::apiResource('/equipment-usage-records', EquipmentUsageRecordController::class)->parameters(['equipment-usage-records' => 'equipmentUsageRecord'])->only(['index', 'update', 'destroy']);
        Route::get('/equipment/{equipment}/files/{field}/{index?}', [EquipmentController::class, 'downloadFile']);
        Route::apiResource('/equipment', EquipmentController::class);
        Route::post('/equipment-labels/preview', [EquipmentLabelController::class, 'preview']);

        Route::post('/calibration-project-labels/preview', [CalibrationProjectLabelController::class, 'preview']);
        Route::apiResource('/calibration-projects', CalibrationProjectController::class)->parameters(['calibration-projects' => 'calibrationProject'])->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('/equipment-calibrations', EquipmentCalibrationController::class)->parameters(['equipment-calibrations' => 'equipmentCalibration'])->only(['index', 'store', 'show', 'update', 'destroy']);

        Route::get('/temp-humidity-records/equipment-lookup', [TempHumidityRecordController::class, 'equipmentLookup']);
        Route::get('/temp-humidity-records', [TempHumidityRecordController::class, 'index']);
        Route::post('/temp-humidity-records', [TempHumidityRecordController::class, 'store']);
        Route::put('/temp-humidity-records/{tempHumidityRecord}', [TempHumidityRecordController::class, 'update']);
        Route::delete('/temp-humidity-records/{tempHumidityRecord}', [TempHumidityRecordController::class, 'destroy']);
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

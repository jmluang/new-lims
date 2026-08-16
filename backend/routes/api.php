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
use App\Http\Controllers\Pdf\CertificateTemplateController;
use App\Http\Controllers\Pdf\DigitalSignatureController;
use App\Http\Controllers\Pdf\HomepageFunctionStampController;
use App\Http\Controllers\Pdf\PdfFileController;
use App\Http\Controllers\Pdf\PdfHandwrittenSigningController;
use App\Http\Controllers\Pdf\PdfPublicRevisionController;
use App\Http\Controllers\Pdf\PdfSigningController;
use App\Http\Controllers\Pdf\PdfVerificationController;
use App\Http\Controllers\Pdf\PdfVerificationLogController;
use App\Http\Controllers\Pdf\PerforationStampController;
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

// Login throttling is handled inside LoginController (failure-based, per email+IP
// and per IP) so successful logins are never blocked — a blanket middleware
// limiter would count every request and lock out users behind a shared/NAT IP.
Route::post('/login', [LoginController::class, 'login']);
Route::post('/register', [LoginController::class, 'register'])->middleware('throttle:auth-register');
Route::get('/register/options', [LoginController::class, 'registerOptions']);

// Public device ingest: temperature/humidity sensors push readings here
// (ported from the legacy example/post.php). Accepts GET or POST.
// Throttled to blunt anonymous flooding of the readings table.
Route::match(['get', 'post'], '/device/temp-humidity', [TempHumidityRecordController::class, 'ingest'])
    ->middleware('throttle:device-temp-humidity');
Route::post('/public/test-order-submissions/customer-lookup', [PublicTestOrderSubmissionController::class, 'lookupCustomer'])
    ->middleware('throttle:public-submission-lookup');
Route::post('/public/test-order-submissions', [PublicTestOrderSubmissionController::class, 'store'])
    ->middleware('throttle:public-submission-store');

// PDF 防篡改公开验证：报告持有人上传 PDF 核验真伪，无需登录。
Route::post('/public/pdf/verify', [PdfVerificationController::class, 'publicVerify'])
    ->middleware('throttle:public-pdf-verify');
Route::get('/public/pdf/revisions/{revisionUuid}', [PdfPublicRevisionController::class, 'revision'])
    ->middleware('throttle:public-pdf-verify');
Route::post('/public/pdf/revisions/{revisionUuid}/verify', [PdfPublicRevisionController::class, 'verify'])
    ->middleware('throttle:public-pdf-verify');
Route::get('/public/pdf/documents/{publicId}', [PdfPublicRevisionController::class, 'document'])
    ->middleware('throttle:public-pdf-verify');

// 签章完成后的临时下载链接。签名台把成品交给浏览器时用的是 blob: 地址，而把
// 下载交给自带下载器的浏览器（360 浏览器等）拿它没办法，自动下载会毫无反应。
// 真实地址处处可用，但普通 <a href> 带不上 SPA 的 bearer token，所以改由链接
// 自身携带授权：签名只对这一个文件生效，且很快过期。
Route::get('/pdf/files/{pdfFile}/temporary-download', [PdfFileController::class, 'temporaryDownload'])
    ->middleware(['signed', 'throttle:pdf-temporary-download'])
    ->name('pdf.files.temporary-download');

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

        // PDF 防篡改系统
        Route::prefix('pdf')->group(function (): void {
            Route::get('/handwritten-signing/options', [PdfHandwrittenSigningController::class, 'planningOptions']);
            Route::post('/signing-sources/inspect', [PdfHandwrittenSigningController::class, 'inspect']);
            Route::post('/signing-sources/{source}/confirm', [PdfHandwrittenSigningController::class, 'confirm']);
            Route::post('/signing-sources/{source}/finalize', [PdfHandwrittenSigningController::class, 'finalize']);
            Route::post('/signing-workflows', [PdfHandwrittenSigningController::class, 'createWorkflow']);
            Route::post('/signing-workflows/{workflow}/prepare', [PdfHandwrittenSigningController::class, 'prepareWorkflow']);
            Route::post('/signing-workflows/{workflow}/cancel', [PdfHandwrittenSigningController::class, 'cancelWorkflow']);
            Route::get('/signing-workflows/{workflow}', [PdfHandwrittenSigningController::class, 'workflow']);
            Route::get('/signing-requests', [PdfHandwrittenSigningController::class, 'signingRequests']);
            Route::get('/signing-requests/{signingRequest}', [PdfHandwrittenSigningController::class, 'signingRequest']);
            Route::post('/signing-requests/{signingRequest}/reject', [PdfHandwrittenSigningController::class, 'rejectSigningRequest']);
            Route::post('/signing-requests/{signingRequest}/appearances', [PdfHandwrittenSigningController::class, 'createAppearance']);
            Route::post('/signing-requests/{signingRequest}/challenge', [PdfHandwrittenSigningController::class, 'createChallenge']);
            Route::post('/signing-requests/{signingRequest}/sign', [PdfHandwrittenSigningController::class, 'claimSigningOperation']);
            Route::get('/signing-operations/{operation}', [PdfHandwrittenSigningController::class, 'signingOperation']);
            Route::get('/revisions/{revisionUuid}/download', [PdfHandwrittenSigningController::class, 'downloadRevision'])
                ->name('pdf.revisions.download');

            Route::get('/signing/options', [PdfSigningController::class, 'options']);
            Route::get('/signing/certificate-templates/{certificateTemplate}/file', [PdfSigningController::class, 'certificateTemplate']);
            Route::post('/signing/process', [PdfSigningController::class, 'process']);

            Route::post('/verification/verify', [PdfVerificationController::class, 'verify']);

            Route::get('/files', [PdfFileController::class, 'index']);
            Route::get('/files/{pdfFile}', [PdfFileController::class, 'show']);
            Route::get('/files/{pdfFile}/download', [PdfFileController::class, 'download']);

            Route::get('/verification-logs', [PdfVerificationLogController::class, 'index']);
            Route::get('/verification-logs/{pdfVerificationLog}', [PdfVerificationLogController::class, 'show']);
            Route::get('/verification-logs/{pdfVerificationLog}/download', [PdfVerificationLogController::class, 'download']);

            // The asset controllers resolve their own models, so ids stay scalar.
            foreach ([
                'digital-signatures' => DigitalSignatureController::class,
                'perforation-stamps' => PerforationStampController::class,
                'function-stamps' => HomepageFunctionStampController::class,
                'certificate-templates' => CertificateTemplateController::class,
            ] as $path => $controller) {
                Route::get("/{$path}", [$controller, 'index']);
                Route::post("/{$path}", [$controller, 'store']);
                Route::post("/{$path}/{id}", [$controller, 'update'])->whereNumber('id');
                Route::delete("/{$path}/{id}", [$controller, 'destroy'])->whereNumber('id');
                Route::get("/{$path}/{id}/file", [$controller, 'file'])->whereNumber('id');
            }
        });

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

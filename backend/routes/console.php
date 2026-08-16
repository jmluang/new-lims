<?php

use App\Models\PdfDocument;
use App\Models\PdfFile;
use App\Models\PdfSigningOperation;
use App\Models\User;
use App\Services\Authorization\PermissionAccess;
use App\Services\Pdf\AuthorizeJavaResultRetirementService;
use App\Services\Pdf\PdfAppearanceRetentionService;
use App\Services\Pdf\PdfDocumentEvidenceHoldService;
use App\Services\Pdf\PdfOperationOrphanFileReconciler;
use App\Services\Pdf\PdfOperationOutboxDispatcher;
use App\Services\Pdf\PdfRevisionIntegrityService;
use App\Services\Pdf\PdfSigningOperationReconciler;
use App\Services\Pdf\ResolvePdfSigningManualReviewService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pdf:dispatch-signing-outbox {--limit=100}', function (): int {
    $count = app(PdfOperationOutboxDispatcher::class)
        ->dispatchPending((int) $this->option('limit'));
    $this->info("Dispatched {$count} PDF signing operation(s).");

    return 0;
})->purpose('Dispatch durable PDF signing operation outbox rows');

Schedule::command('pdf:dispatch-signing-outbox --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('pdf:reconcile-signing-operations {--limit=100}', function (): int {
    $count = app(PdfSigningOperationReconciler::class)
        ->sweep((int) $this->option('limit'));
    $this->info("Reconciled {$count} PDF signing operation(s).");

    return 0;
})->purpose('Recover unleased or expired PDF signing operations through fenced outbox replay');

Schedule::command('pdf:reconcile-signing-operations --limit=100')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('pdf:reconcile-signing-orphans {--limit=100}', function (): int {
    $count = app(PdfOperationOrphanFileReconciler::class)
        ->sweep((int) $this->option('limit'));
    $this->info("Quarantined {$count} orphan PDF signing operation file(s).");

    return 0;
})->purpose('Move unreferenced PDF signing operation files into crash-recoverable quarantine');

Schedule::command('pdf:reconcile-signing-orphans --limit=100')
    ->everyTenMinutes()
    ->withoutOverlapping();

Artisan::command('pdf:sweep-revision-integrity {--limit=100}', function (): int {
    $count = app(PdfRevisionIntegrityService::class)->sweep((int) $this->option('limit'));
    $this->info("Withdrew {$count} unavailable published PDF revision(s).");

    return 0;
})->purpose('Withdraw published PDF revisions whose immutable bytes are missing or breached');

Schedule::command('pdf:sweep-revision-integrity --limit=100')
    ->everyTenMinutes()
    ->withoutOverlapping();

Artisan::command(
    'pdf:restore-revision-integrity {revisionUuid} {reasonCode} {actorUserId}',
    function (): int {
        $actor = User::query()->findOrFail((int) $this->argument('actorUserId'));
        abort_unless(
            app(PermissionAccess::class)->userCan($actor, 'pdf.evidence_hold.manage'),
            403,
            'PDF_REVISION_INTEGRITY_RESTORE_PERMISSION_REQUIRED',
        );
        $revision = PdfFile::query()->where('revision_uuid', $this->argument('revisionUuid'))->firstOrFail();
        $restored = app(PdfRevisionIntegrityService::class)->restore(
            $revision,
            $actor,
            (string) $this->argument('reasonCode'),
        );
        $this->info("Restored immutable PDF revision {$restored->revision_uuid}.");

        return 0;
    },
)->purpose('Restore an unavailable published revision after exact-byte and signature verification');

Artisan::command('pdf:sweep-signing-appearances {--limit=100}', function (): int {
    $count = app(PdfAppearanceRetentionService::class)
        ->sweep((int) $this->option('limit'));
    $this->info("Advanced {$count} appearance retirement phase(s).");

    return 0;
})->purpose('Advance fail-closed PDF appearance retention retirement phases');

Schedule::command('pdf:sweep-signing-appearances --limit=100')
    ->hourly()
    ->withoutOverlapping();

Artisan::command('pdf:authorize-java-result-retirement {--limit=100}', function (): int {
    $count = app(AuthorizeJavaResultRetirementService::class)
        ->sweep((int) $this->option('limit'));
    $this->info("Authorized {$count} Java signing result retirement(s).");

    return 0;
})->purpose('Authorize Java result retirement only after formal revision verification');

Schedule::command('pdf:authorize-java-result-retirement --limit=100')
    ->hourly()
    ->withoutOverlapping();

Artisan::command(
    'pdf:resolve-signing-manual-review {operationUuid} {decision} {reasonCode} {resolutionFingerprint} {actorUserId}',
    function (): int {
        $actor = User::query()->findOrFail((int) $this->argument('actorUserId'));
        abort_unless(
            app(PermissionAccess::class)
                ->userCan($actor, 'pdf.manual_review.resolve'),
            403,
            'PDF_MANUAL_REVIEW_PERMISSION_REQUIRED',
        );
        $operation = PdfSigningOperation::query()
            ->where('operation_uuid', $this->argument('operationUuid'))
            ->firstOrFail();
        $resolved = app(ResolvePdfSigningManualReviewService::class)->resolve(
            $operation,
            (string) $this->argument('decision'),
            (string) $this->argument('reasonCode'),
            strtolower((string) $this->argument('resolutionFingerprint')),
            $actor,
        );
        $this->info("Resolved {$resolved->operation_uuid} as {$resolved->state}.");

        return 0;
    },
)->purpose('Resolve one PDF signing manual-review operation with an audited fingerprint');

Artisan::command(
    'pdf:document-evidence-hold {documentUuid} {action} {reasonBit} {reasonCode} {actorUserId} {--legal-until=}',
    function (): int {
        $actor = User::query()->findOrFail((int) $this->argument('actorUserId'));
        abort_unless(
            app(PermissionAccess::class)->userCan($actor, 'pdf.evidence_hold.manage'),
            403,
            'PDF_EVIDENCE_HOLD_PERMISSION_REQUIRED',
        );
        $document = PdfDocument::query()
            ->where('document_uuid', $this->argument('documentUuid'))
            ->firstOrFail();
        $action = (string) $this->argument('action');
        $service = app(PdfDocumentEvidenceHoldService::class);
        $result = match ($action) {
            'install' => $service->install(
                $document,
                (int) $this->argument('reasonBit'),
                (string) $this->argument('reasonCode'),
                $actor,
                $this->option('legal-until')
                    ? Carbon::parse((string) $this->option('legal-until'))
                    : null,
            ),
            'release' => $service->release(
                $document,
                (int) $this->argument('reasonBit'),
                (string) $this->argument('reasonCode'),
                $actor,
            ),
            default => throw new InvalidArgumentException('Action must be install or release.'),
        };
        $this->info("Document {$result->document_uuid} evidence hold mask is {$result->evidence_hold_mask}.");

        return 0;
    },
)->purpose('Install or release an audited document-wide PDF evidence hold');

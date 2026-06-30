<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\Audit\AuditLogger;
use App\Services\System\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

class RunSystemBackupJob implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    public int $tries;

    public function __construct(public int $backupRunId)
    {
        $this->timeout = (int) config('backup.backup.job.timeout', 1800);
        $this->tries = (int) config('backup.backup.job.tries', 1);
    }

    public function handle(AuditLogger $auditLogger, BackupService $backupService): void
    {
        $backupRun = BackupRun::query()->findOrFail($this->backupRunId);

        try {
            $backupRun = $backupService->run($backupRun);

            $auditLogger->record(
                actor: null,
                action: 'system.backups.run',
                module: 'system.backups',
                subject: $backupRun,
                after: $backupRun->only(['type', 'status', 'database_path', 'files_path', 'size_bytes']),
                requestMeta: ['request_id' => 'backup-job-'.$backupRun->id],
            );
        } catch (Throwable $throwable) {
            $backupRun = BackupRun::query()->find($this->backupRunId);

            if ($backupRun) {
                $backupRun->update([
                    'status' => 'failed',
                    'error_message' => $throwable->getMessage(),
                    'finished_at' => Carbon::now(),
                ]);

                $auditLogger->record(
                    actor: null,
                    action: 'system.backups.failed',
                    module: 'system.backups',
                    subject: $backupRun->fresh(),
                    after: $backupRun->fresh()->only(['type', 'status', 'error_message']),
                    requestMeta: ['request_id' => 'backup-job-'.$backupRun->id],
                );
            }

            throw $throwable;
        }
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) config('backup.backup.lock.key', 'system-backup'), 5))
                ->expireAfter((int) config('backup.backup.job.timeout', 1800) + 60)
                ->shared(),
        ];
    }

    public function failed(?Throwable $exception): void
    {
        $backupRun = BackupRun::query()->find($this->backupRunId);

        if (! $backupRun || in_array($backupRun->status, ['succeeded', 'failed'], true)) {
            return;
        }

        $backupRun->update([
            'status' => 'failed',
            'error_message' => $exception?->getMessage() ?: 'Backup job failed.',
            'finished_at' => Carbon::now(),
        ]);
    }
}

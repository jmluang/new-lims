<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class RunSystemBackup extends Command
{
    protected $signature = 'lims:backup {--type=manual}';

    protected $description = 'Record a LIMS backup run';

    public function handle(AuditLogger $auditLogger): int
    {
        $backupRun = BackupRun::query()->create([
            'type' => $this->option('type'),
            'status' => 'running',
            'started_at' => Carbon::now(),
        ]);

        try {
            $backupRun->update([
                'status' => 'succeeded',
                'database_path' => 'pending-spatie-backup-integration',
                'files_path' => 'pending-spatie-backup-integration',
                'size_bytes' => 0,
                'finished_at' => Carbon::now(),
            ]);

            $auditLogger->record(
                actor: null,
                action: 'system.backups.run',
                module: 'system.backups',
                subject: $backupRun->fresh(),
                after: $backupRun->fresh()->only(['type', 'status', 'database_path', 'files_path', 'size_bytes']),
                requestMeta: ['request_id' => 'console-backup-'.$backupRun->id],
            );

            return self::SUCCESS;
        } catch (Throwable $throwable) {
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
                requestMeta: ['request_id' => 'console-backup-'.$backupRun->id],
            );

            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}

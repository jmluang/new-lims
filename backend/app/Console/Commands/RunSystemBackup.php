<?php

namespace App\Console\Commands;

use App\Jobs\RunSystemBackupJob;
use App\Models\BackupRun;
use App\Services\Audit\AuditLogger;
use App\Services\System\BackupService;
use Illuminate\Console\Command;
use Throwable;

class RunSystemBackup extends Command
{
    protected $signature = 'lims:backup {--type=manual}';

    protected $description = 'Record a LIMS backup run';

    public function handle(AuditLogger $auditLogger, BackupService $backupService): int
    {
        $backupRun = BackupRun::query()->create([
            'type' => $this->option('type'),
            'status' => 'pending',
        ]);

        try {
            app(RunSystemBackupJob::class, ['backupRunId' => $backupRun->id])->handle($auditLogger, $backupService);

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}

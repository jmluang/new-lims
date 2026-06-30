<?php

namespace Tests\Feature\System;

use App\Jobs\RunSystemBackupJob;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\System\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::disk('local')->deleteDirectory('backups');
        Storage::disk('local')->deleteDirectory('uploads');
        DB::disconnect('backup_source');
        @unlink(storage_path('framework/testing-backup-source.sqlite'));
    }

    public function test_backup_command_writes_database_dump_file_archive_and_audit_log(): void
    {
        Carbon::setTestNow('2026-06-15 12:17:08');
        Storage::disk('local')->put('uploads/evidence.txt', 'file evidence');

        $this->artisan('lims:backup', ['--type' => 'daily'])
            ->assertSuccessful();

        $backupRun = BackupRun::query()->firstOrFail();

        $this->assertSame('daily', $backupRun->type);
        $this->assertSame('succeeded', $backupRun->status);
        $this->assertNotNull($backupRun->database_path);
        $this->assertNotNull($backupRun->files_path);
        Storage::disk('local')->assertExists($backupRun->database_path);
        Storage::disk('local')->assertExists($backupRun->files_path);
        $dump = Storage::disk('local')->get($backupRun->database_path);
        $this->assertStringContainsString('backup_runs', $dump);
        $this->assertStringContainsString('-- created_at: 2026-06-15 12:17:08', $dump);
        $this->assertStringNotContainsString('T12:17:08', $dump);
        $this->assertGreaterThan(0, $backupRun->size_bytes);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'system.backups.run',
            'module' => 'system.backups',
        ]);
    }

    public function test_backup_create_endpoint_queues_backup_without_running_it_synchronously(): void
    {
        Queue::fake();
        $this->mock(BackupService::class)->shouldNotReceive('run');
        config([
            'queue.default' => 'sync',
            'backup.backup.job.connection' => 'backups',
            'backup.backup.job.queue' => 'backups',
        ]);

        Sanctum::actingAs($this->userWithPermissions(['system.backups.create']));

        $this->postJson('/api/backups', ['type' => 'manual'])
            ->assertAccepted()
            ->assertJsonPath('data.type', 'manual')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.database_path', null)
            ->assertJsonPath('data.files_path', null);

        $backupRun = BackupRun::query()->firstOrFail();

        $this->assertSame('pending', $backupRun->status);
        $this->assertNull($backupRun->started_at);
        $this->assertNull($backupRun->finished_at);
        Storage::disk('local')->assertMissing($backupRun->database_path ?? 'backups/not-created.sql');

        Queue::assertPushedOn(
            'backups',
            RunSystemBackupJob::class,
            fn (RunSystemBackupJob $job): bool => $job->backupRunId === $backupRun->id
        );
    }

    public function test_backup_create_endpoint_can_execute_immediately_when_backup_queue_connection_is_sync(): void
    {
        Carbon::setTestNow('2026-06-30 23:20:00');
        config([
            'backup.backup.job.connection' => 'sync',
            'backup.backup.job.queue' => 'backups',
        ]);
        $this->mock(BackupService::class)
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function (BackupRun $backupRun): BackupRun {
                $backupRun->update([
                    'status' => 'succeeded',
                    'database_path' => 'backups/sync/database.sql',
                    'files_path' => 'backups/sync/files.zip',
                    'size_bytes' => 128,
                    'started_at' => Carbon::now(),
                    'finished_at' => Carbon::now(),
                ]);

                return $backupRun->fresh();
            });

        Sanctum::actingAs($this->userWithPermissions(['system.backups.create']));

        $this->postJson('/api/backups', ['type' => 'manual'])
            ->assertAccepted()
            ->assertJsonPath('data.type', 'manual')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.database_path', 'backups/sync/database.sql')
            ->assertJsonPath('data.files_path', 'backups/sync/files.zip');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'system.backups.run',
            'module' => 'system.backups',
        ]);
    }

    public function test_backup_create_endpoint_marks_run_failed_when_queue_push_fails(): void
    {
        Carbon::setTestNow('2026-06-30 23:15:00');
        config([
            'backup.backup.job.connection' => 'backups',
        ]);
        Queue::shouldReceive('connection')
            ->once()
            ->with('backups')
            ->andThrow(new RuntimeException('backup queue unavailable'));

        Sanctum::actingAs($this->userWithPermissions(['system.backups.create']));

        $this->postJson('/api/backups', ['type' => 'manual'])
            ->assertStatus(500);

        $this->assertDatabaseHas('backup_runs', [
            'type' => 'manual',
            'status' => 'failed',
            'error_message' => 'backup queue unavailable',
            'finished_at' => '2026-06-30 23:15:00',
        ]);
    }

    public function test_backup_queue_retry_after_exceeds_backup_job_timeout(): void
    {
        config([
            'backup.backup.job.connection' => 'backups',
        ]);

        $this->assertSame('backups', config('backup.backup.job.connection'));
        $this->assertGreaterThan(
            config('backup.backup.job.timeout'),
            config('queue.connections.'.config('backup.backup.job.connection').'.retry_after')
        );
    }

    public function test_backup_queue_connection_defaults_to_sync_when_global_queue_connection_is_sync(): void
    {
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('sync', config('backup.backup.job.connection'));
    }

    public function test_backup_queue_runtime_documentation_matches_dedicated_queue_and_sync_fallback(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));
        $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('BACKUP_QUEUE_CONNECTION=backups', $envExample);
        $this->assertStringContainsString('BACKUP_QUEUE_RETRY_AFTER=1920', $envExample);
        $this->assertStringContainsString('BACKUP_JOB_TIMEOUT=1800', $envExample);
        $this->assertStringContainsString('BACKUP_QUEUE_CONNECTION=sync', file_get_contents(base_path('../README.md')));
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', file_get_contents(base_path('../README.md')));
        $this->assertContains(
            'npx concurrently -c "#93c5fd,#c4b5fd,#fb7185,#fdba74,#86efac" "php artisan serve" "php artisan queue:listen --tries=1 --timeout=0" "php artisan queue:work backups --queue=backups --tries=1 --timeout=1800" "php artisan pail --timeout=0" "npm run dev" --names=server,queue,backup-queue,logs,vite --kill-others',
            $composer['scripts']['dev']
        );
    }

    public function test_backup_job_records_failed_run_when_backup_service_fails(): void
    {
        $backupRun = BackupRun::query()->create([
            'type' => 'manual',
            'status' => 'pending',
        ]);

        $this->mock(BackupService::class)
            ->shouldReceive('run')
            ->once()
            ->andThrow(new RuntimeException('mysqldump timed out'));

        try {
            app(RunSystemBackupJob::class, ['backupRunId' => $backupRun->id])->handle(
                app(AuditLogger::class),
                app(BackupService::class),
            );
            $this->fail('The backup job should rethrow the service exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('mysqldump timed out', $exception->getMessage());
        }

        $this->assertDatabaseHas('backup_runs', [
            'id' => $backupRun->id,
            'status' => 'failed',
            'error_message' => 'mysqldump timed out',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'system.backups.failed',
            'module' => 'system.backups',
            'subject_id' => (string) $backupRun->id,
        ]);
    }

    public function test_backup_job_uses_global_queue_overlap_lock_longer_than_the_job_timeout(): void
    {
        config([
            'backup.backup.lock.key' => 'test-system-backup-lock',
            'backup.backup.job.timeout' => 1800,
        ]);

        $middleware = (new RunSystemBackupJob(1))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('test-system-backup-lock', $middleware[0]->key);
        $this->assertSame(5, $middleware[0]->releaseAfter);
        $this->assertSame(1860, $middleware[0]->expiresAfter);
        $this->assertTrue($middleware[0]->shareKey);
    }

    public function test_backup_service_marks_backup_failed_when_another_backup_holds_the_lock(): void
    {
        config([
            'backup.backup.lock.key' => 'test-system-backup-lock',
            'backup.backup.lock.seconds' => 60,
        ]);

        $lock = Cache::lock('test-system-backup-lock', 60);
        $this->assertTrue($lock->get());

        $backupRun = BackupRun::query()->create([
            'type' => 'manual',
            'status' => 'pending',
        ]);

        try {
            try {
                app(BackupService::class)->run($backupRun);
                $this->fail('The backup service should reject a concurrent backup run.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Another backup is already running.', $exception->getMessage());
            }
        } finally {
            $lock->release();
        }

        $this->assertDatabaseHas('backup_runs', [
            'id' => $backupRun->id,
            'status' => 'failed',
            'error_message' => 'Another backup is already running.',
        ]);
    }

    public function test_backup_service_uses_streaming_mysqldump_for_mysql_database_exports(): void
    {
        $processes = [];

        Process::fake(function ($process) use (&$processes) {
            $processes[] = $process;
            $resultFile = collect($process->command)
                ->first(fn (string $argument): bool => str_starts_with($argument, '--result-file='));

            file_put_contents(substr($resultFile, strlen('--result-file=')), '-- streamed mysql dump');

            return Process::result();
        });

        config([
            'database.connections.backup_source' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'lims',
                'username' => 'backup_user',
                'password' => 'secret',
                'unix_socket' => '',
            ],
            'backup.backup.source.database_connection' => 'backup_source',
            'backup.backup.source.exclude_tables' => [
                'telescope_entries',
                'telescope_entries_tags',
            ],
            'backup.backup.source.database_dump.timeout' => 123,
        ]);

        $backupRun = BackupRun::query()->create([
            'type' => 'manual',
            'status' => 'pending',
        ]);

        app(BackupService::class)->run($backupRun);

        $backupRun->refresh();

        $this->assertSame('succeeded', $backupRun->status);
        $this->assertSame('-- streamed mysql dump', Storage::disk('local')->get($backupRun->database_path));
        $this->assertCount(1, $processes);

        $command = $processes[0]->command;

        $this->assertContains('mysqldump', $command);
        $this->assertContains('--single-transaction', $command);
        $this->assertContains('--quick', $command);
        $this->assertContains('--host=127.0.0.1', $command);
        $this->assertContains('--port=3306', $command);
        $this->assertContains('--user=backup_user', $command);
        $this->assertContains('--ignore-table=lims.telescope_entries', $command);
        $this->assertContains('--ignore-table=lims.telescope_entries_tags', $command);
        $this->assertContains('lims', $command);
        $this->assertSame(123, $processes[0]->timeout);
        $this->assertSame('secret', $processes[0]->environment['MYSQL_PWD'] ?? null);
    }

    public function test_backup_command_records_failed_run_when_backup_service_fails(): void
    {
        $this->mock(BackupService::class)
            ->shouldReceive('run')
            ->once()
            ->andThrow(new RuntimeException('backup disk unavailable'));

        $this->artisan('lims:backup', ['--type' => 'daily'])
            ->assertFailed();

        $this->assertDatabaseHas('backup_runs', [
            'type' => 'daily',
            'status' => 'failed',
            'error_message' => 'backup disk unavailable',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'system.backups.failed',
            'module' => 'system.backups',
        ]);
    }

    public function test_backup_command_dumps_configured_source_connection_instead_of_refreshed_default_database(): void
    {
        $sourcePath = storage_path('framework/testing-backup-source.sqlite');
        touch($sourcePath);

        config([
            'database.connections.backup_source' => [
                'driver' => 'sqlite',
                'database' => $sourcePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'backup.backup.source.database_connection' => 'backup_source',
        ]);

        DB::purge('backup_source');
        DB::connection('backup_source')->getSchemaBuilder()->create('business_backup_probe', function ($table): void {
            $table->id();
            $table->string('marker');
        });
        DB::connection('backup_source')->table('business_backup_probe')->insert([
            'marker' => 'probe_should_survive_refresh',
        ]);

        $this->artisan('lims:backup', ['--type' => 'daily'])->assertSuccessful();

        $dump = Storage::disk('local')->get(BackupRun::query()->firstOrFail()->database_path);

        $this->assertStringContainsString('business_backup_probe', $dump);
        $this->assertStringContainsString('probe_should_survive_refresh', $dump);
        $this->assertStringNotContainsString('backup_runs', $dump);
    }

    public function test_backup_command_excludes_telescope_tables_from_database_dump(): void
    {
        $sourcePath = storage_path('framework/testing-backup-source.sqlite');
        touch($sourcePath);

        config([
            'database.connections.backup_source' => [
                'driver' => 'sqlite',
                'database' => $sourcePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'backup.backup.source.database_connection' => 'backup_source',
        ]);

        DB::purge('backup_source');
        DB::connection('backup_source')->getSchemaBuilder()->create('business_backup_probe', function ($table): void {
            $table->id();
            $table->string('marker');
        });
        DB::connection('backup_source')->getSchemaBuilder()->create('telescope_entries', function ($table): void {
            $table->id();
            $table->string('secret_payload');
        });
        DB::connection('backup_source')->getSchemaBuilder()->create('telescope_entries_tags', function ($table): void {
            $table->string('entry_uuid');
            $table->string('tag');
        });
        DB::connection('backup_source')->getSchemaBuilder()->create('telescope_monitoring', function ($table): void {
            $table->string('tag')->primary();
        });

        DB::connection('backup_source')->table('business_backup_probe')->insert([
            'marker' => 'business_data_should_be_backed_up',
        ]);
        DB::connection('backup_source')->table('telescope_entries')->insert([
            'secret_payload' => 'telescope_payload_should_not_be_backed_up',
        ]);
        DB::connection('backup_source')->table('telescope_entries_tags')->insert([
            'entry_uuid' => 'entry-1',
            'tag' => 'telescope_tag_should_not_be_backed_up',
        ]);
        DB::connection('backup_source')->table('telescope_monitoring')->insert([
            'tag' => 'telescope_monitor_should_not_be_backed_up',
        ]);

        $this->artisan('lims:backup', ['--type' => 'daily'])->assertSuccessful();

        $dump = Storage::disk('local')->get(BackupRun::query()->firstOrFail()->database_path);

        $this->assertStringContainsString('business_backup_probe', $dump);
        $this->assertStringContainsString('business_data_should_be_backed_up', $dump);
        $this->assertStringNotContainsString('telescope_entries', $dump);
        $this->assertStringNotContainsString('telescope_entries_tags', $dump);
        $this->assertStringNotContainsString('telescope_monitoring', $dump);
        $this->assertStringNotContainsString('telescope_payload_should_not_be_backed_up', $dump);
        $this->assertStringNotContainsString('telescope_tag_should_not_be_backed_up', $dump);
        $this->assertStringNotContainsString('telescope_monitor_should_not_be_backed_up', $dump);
    }

    public function test_backup_restore_requires_permission_and_records_audit_log(): void
    {
        Carbon::setTestNow('2026-06-15 12:17:08');
        Storage::disk('local')->put('backups/test/database.sql', '-- restore candidate');
        Storage::disk('local')->put('backups/test/files.zip', 'zip-bytes');
        $backupRun = BackupRun::query()->create([
            'type' => 'manual',
            'status' => 'succeeded',
            'database_path' => 'backups/test/database.sql',
            'files_path' => 'backups/test/files.zip',
            'size_bytes' => 32,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        Sanctum::actingAs($this->userWithPermissions(['system.backups.read']));
        $this->postJson("/api/backups/{$backupRun->id}/restore")->assertForbidden();

        Sanctum::actingAs($this->userWithPermissions(['system.backups.restore']));
        $this->postJson("/api/backups/{$backupRun->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.restored', true);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'system.backups.restore',
            'module' => 'system.backups',
            'subject_id' => (string) $backupRun->id,
        ]);
        $restoreMetadata = Storage::disk('local')->get("backups/restores/{$backupRun->id}.json");
        $this->assertStringContainsString('"restored_at":"2026-06-15 12:17:08"', $restoreMetadata);
        $this->assertStringNotContainsString('T12:17:08', $restoreMetadata);
    }

    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create(['name' => 'test_backup_'.str()->random(8), 'guard_name' => 'web']);
        $role->givePermissionTo(collect($permissions)->map(
            fn (string $permission): Permission => Permission::findOrCreate($permission, 'web')
        ));

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

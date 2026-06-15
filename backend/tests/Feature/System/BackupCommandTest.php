<?php

namespace Tests\Feature\System;

use App\Models\BackupRun;
use App\Models\User;
use App\Services\System\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

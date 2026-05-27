<?php

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_command_records_backup_run_and_audit_log(): void
    {
        $this->artisan('lims:backup', ['--type' => 'daily'])
            ->assertSuccessful();

        $this->assertDatabaseHas('backup_runs', [
            'type' => 'daily',
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'system.backups.run',
            'module' => 'system.backups',
        ]);
    }
}

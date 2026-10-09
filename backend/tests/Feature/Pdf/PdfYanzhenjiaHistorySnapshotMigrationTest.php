<?php

namespace Tests\Feature\Pdf;

use App\Models\PdfFile;
use App\Models\PdfYanzhenjiaSync;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PdfYanzhenjiaHistorySnapshotMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_backfills_live_legacy_rows_without_touching_payloads_or_history_timestamps(): void
    {
        $migration = require database_path('migrations/2026_10_09_000200_snapshot_pdf_yanzhenjia_report_identity.php');
        $migration->down();
        $file = PdfFile::query()->create([
            'file_id' => 'SYNC-BACKFILL',
            'file_name' => 'backfill.pdf',
            'cover_report_number' => 'REPORT-BACKFILL',
            'sha256_hash' => hash('sha256', 'backfill'),
            'file_size' => 100,
            'created_by' => 'Operator',
        ]);
        $legacy = PdfYanzhenjiaSync::query()->create([
            'pdf_file_id' => $file->id,
            'api_version' => 'legacy',
            'status' => 'succeeded',
            'remote_file_id' => 31,
        ]);
        $v1 = PdfYanzhenjiaSync::query()->create([
            'pdf_file_id' => $file->id,
            'api_version' => 'v1',
            'request_payload' => ['report_number' => 'FROZEN', 'sha256' => hash('sha256', 'frozen')],
        ]);
        $orphan = PdfYanzhenjiaSync::query()->create([
            'pdf_file_id' => null,
            'api_version' => 'legacy',
            'source_file_id' => 'ALREADY-DELETED',
            'source_deleted_at' => now(),
            'status' => 'succeeded',
        ]);
        $before = DB::table('pdf_yanzhenjia_syncs')->orderBy('id')->get()->keyBy('id');

        $migration->up();

        foreach ([$legacy, $v1] as $sync) {
            $this->assertSame('REPORT-BACKFILL', $sync->fresh()->source_report_number);
            $this->assertSame($file->sha256_hash, $sync->fresh()->source_sha256);
        }
        foreach ([$legacy, $v1, $orphan] as $sync) {
            $after = DB::table('pdf_yanzhenjia_syncs')->where('id', $sync->id)->first();
            $this->assertSame($before[$sync->id]->request_payload, $after->request_payload);
            $this->assertSame($before[$sync->id]->updated_at, $after->updated_at);
            $this->assertSame($before[$sync->id]->status, $after->status);
        }
        $this->assertNull($orphan->fresh()->source_report_number);
        $this->assertNull($orphan->fresh()->source_sha256);
        $this->assertSame('ALREADY-DELETED', $orphan->fresh()->source_file_id);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('pdf_yanzhenjia_syncs', 'source_sha256'));
        $migration->up();
        $this->assertSame($file->sha256_hash, $legacy->fresh()->source_sha256);
    }

    public function test_backfill_does_not_overwrite_a_snapshot_written_by_a_concurrent_deletion(): void
    {
        $migration = require database_path('migrations/2026_10_09_000200_snapshot_pdf_yanzhenjia_report_identity.php');
        $migration->down();
        $file = PdfFile::query()->create([
            'file_id' => 'SYNC-RACE',
            'file_name' => 'race.pdf',
            'cover_report_number' => 'OLD-REPORT',
            'sha256_hash' => hash('sha256', 'old'),
            'file_size' => 100,
            'created_by' => 'Operator',
        ]);
        $sync = PdfYanzhenjiaSync::query()->create(['pdf_file_id' => $file->id]);
        $deleted = false;
        DB::listen(function (QueryExecuted $query) use ($sync, &$deleted): void {
            if (! $deleted && str_starts_with($query->sql, 'select')
                && str_contains($query->sql, 'cover_report_number')
                && str_contains($query->sql, 'pdf_files')) {
                $deleted = true;
                // Interleave a deletion after the backfill has read the file.
                DB::table('pdf_yanzhenjia_syncs')->where('id', $sync->id)->update([
                    'pdf_file_id' => null,
                    'source_deleted_at' => now(),
                    'source_report_number' => 'DELETION-SNAPSHOT',
                    'source_sha256' => hash('sha256', 'deletion snapshot'),
                ]);
            }
        });

        $migration->up();

        $this->assertTrue($deleted);
        $this->assertNull($sync->fresh()->pdf_file_id);
        $this->assertSame('DELETION-SNAPSHOT', $sync->fresh()->source_report_number);
        $this->assertSame(hash('sha256', 'deletion snapshot'), $sync->fresh()->source_sha256);
    }
}

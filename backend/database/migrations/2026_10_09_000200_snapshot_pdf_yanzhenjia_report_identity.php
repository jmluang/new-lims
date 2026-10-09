<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_yanzhenjia_syncs', function (Blueprint $table): void {
            $table->string('source_report_number')->nullable();
            $table->char('source_sha256', 64)->nullable();
        });

        // Keep identity outside the frozen v1 request. Deleted legacy rows
        // without a payload or remaining PDF cannot be reconstructed safely.
        DB::table('pdf_yanzhenjia_syncs')->whereNotNull('pdf_file_id')->orderBy('id')
            ->chunkById(100, function ($syncs): void {
                foreach ($syncs as $sync) {
                    $file = DB::table('pdf_files')->where('id', $sync->pdf_file_id)
                        ->first(['cover_report_number', 'sha256_hash']);
                    if ($file === null) {
                        continue;
                    }

                    foreach (['source_report_number' => $file->cover_report_number, 'source_sha256' => $file->sha256_hash] as $column => $value) {
                        // Do not overwrite a snapshot written by a concurrent
                        // delete, or reattach an already detached history row.
                        DB::table('pdf_yanzhenjia_syncs')->where('id', $sync->id)
                            ->where('pdf_file_id', $sync->pdf_file_id)->whereNull($column)
                            ->update([$column => $value]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('pdf_yanzhenjia_syncs', function (Blueprint $table): void {
            $table->dropColumn(['source_report_number', 'source_sha256']);
        });
    }
};

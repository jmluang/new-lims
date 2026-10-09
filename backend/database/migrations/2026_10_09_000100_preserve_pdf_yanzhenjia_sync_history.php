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
            $table->string('source_file_id')->nullable();
            $table->string('source_file_name')->nullable();
            $table->timestamp('source_deleted_at')->nullable();
        });

        DB::table('pdf_yanzhenjia_syncs')->orderBy('id')->chunkById(100, function ($syncs): void {
            foreach ($syncs as $sync) {
                $file = DB::table('pdf_files')->where('id', $sync->pdf_file_id)->first(['file_id', 'file_name']);
                if ($file !== null) {
                    DB::table('pdf_yanzhenjia_syncs')->where('id', $sync->id)->update([
                        'source_file_id' => $file->file_id,
                        'source_file_name' => $file->file_name,
                    ]);
                }
            }
        });

        Schema::table('pdf_yanzhenjia_syncs', function (Blueprint $table): void {
            $table->dropForeign(['pdf_file_id']);
            $table->foreignId('pdf_file_id')->nullable()->change();
            $table->foreign('pdf_file_id')->references('id')->on('pdf_files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // A deleted PDF cannot be reattached to a sync record. Keep history
        // rather than silently discarding it on rollback.
        if (DB::table('pdf_yanzhenjia_syncs')->whereNull('pdf_file_id')->exists()) {
            throw new RuntimeException('Cannot restore cascading PDF deletion while orphaned sync history exists.');
        }

        Schema::table('pdf_yanzhenjia_syncs', function (Blueprint $table): void {
            $table->dropForeign(['pdf_file_id']);
            $table->foreignId('pdf_file_id')->nullable(false)->change();
            $table->foreign('pdf_file_id')->references('id')->on('pdf_files')->cascadeOnDelete();
            $table->dropColumn(['source_file_id', 'source_file_name', 'source_deleted_at']);
        });
    }
};

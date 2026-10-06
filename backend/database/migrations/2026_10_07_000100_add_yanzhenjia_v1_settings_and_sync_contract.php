<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SYNC_TABLE = 'pdf_yanzhenjia_syncs';

    private const SETTINGS_TABLE = 'pdf_yanzhenjia_settings';

    private const LEGACY_FILE_INDEX = 'pdf_yanzhenjia_syncs_pdf_file_id_unique';

    private const SUPPORT_FILE_INDEX = 'pdf_yanzhenjia_syncs_pdf_file_id_support_index';

    private const VERSION_INDEX = 'pdf_yanzhenjia_sync_file_version_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::SETTINGS_TABLE)) {
            Schema::create(self::SETTINGS_TABLE, function (Blueprint $table): void {
                $table->tinyIncrements('id');
                $table->boolean('enabled')->default(false);
                $table->string('appid', 32)->nullable();
                $table->text('secret')->nullable();
                $table->timestamps();
            });
        }

        // MySQL uses the existing single-column unique index to support this
        // foreign key. Add a replacement child-side index before removing it.
        if (! $this->hasStandaloneFileIndex()) {
            Schema::table(self::SYNC_TABLE, function (Blueprint $table): void {
                $table->index('pdf_file_id', self::SUPPORT_FILE_INDEX);
            });
        }

        if (! Schema::hasColumn(self::SYNC_TABLE, 'api_version')) {
            Schema::table(self::SYNC_TABLE, function (Blueprint $table): void {
                $table->string('api_version', 16)->default('legacy');
            });
        }

        if (! Schema::hasColumn(self::SYNC_TABLE, 'target_appid')) {
            Schema::table(self::SYNC_TABLE, function (Blueprint $table): void {
                $table->string('target_appid', 32)->nullable();
            });
        }

        if (! Schema::hasColumn(self::SYNC_TABLE, 'request_payload')) {
            Schema::table(self::SYNC_TABLE, function (Blueprint $table): void {
                $table->json('request_payload')->nullable();
            });
        }

        // The composite unique index also begins with pdf_file_id and is the
        // final index needed by the foreign key after the legacy unique drops.
        if (! Schema::hasIndex(self::SYNC_TABLE, self::VERSION_INDEX)) {
            Schema::table(self::SYNC_TABLE, function (Blueprint $table): void {
                $table->unique(['pdf_file_id', 'api_version'], self::VERSION_INDEX);
            });
        }

        if (Schema::hasIndex(self::SYNC_TABLE, self::LEGACY_FILE_INDEX)) {
            Schema::table(self::SYNC_TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_FILE_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable(self::SYNC_TABLE)) {
            if (Schema::hasColumn(self::SYNC_TABLE, 'api_version')
                && DB::table(self::SYNC_TABLE)
                    ->select('pdf_file_id')
                    ->groupBy('pdf_file_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->exists()) {
                throw new RuntimeException('Cannot roll back Yanzhenjia sync versions while a PDF has multiple sync records.');
            }

            // Restore a single-column FK index before removing the v1 index.
            if (! Schema::hasIndex(self::SYNC_TABLE, self::LEGACY_FILE_INDEX)) {
                Schema::table(self::SYNC_TABLE, function (Blueprint $table): void {
                    $table->unique('pdf_file_id', self::LEGACY_FILE_INDEX);
                });
            }

            if (Schema::hasIndex(self::SYNC_TABLE, self::VERSION_INDEX)) {
                Schema::table(self::SYNC_TABLE, function (Blueprint $table): void {
                    $table->dropUnique(self::VERSION_INDEX);
                });
            }

            if (Schema::hasIndex(self::SYNC_TABLE, self::SUPPORT_FILE_INDEX)) {
                Schema::table(self::SYNC_TABLE, function (Blueprint $table): void {
                    $table->dropIndex(self::SUPPORT_FILE_INDEX);
                });
            }

            foreach (['api_version', 'target_appid', 'request_payload'] as $column) {
                if (Schema::hasColumn(self::SYNC_TABLE, $column)) {
                    Schema::table(self::SYNC_TABLE, function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        Schema::dropIfExists(self::SETTINGS_TABLE);
    }

    private function hasStandaloneFileIndex(): bool
    {
        foreach (Schema::getIndexes(self::SYNC_TABLE) as $index) {
            if (($index['columns'] ?? []) === ['pdf_file_id']
                && ! ($index['unique'] ?? false)
                && ! ($index['primary'] ?? false)) {
                return true;
            }
        }

        return false;
    }
};

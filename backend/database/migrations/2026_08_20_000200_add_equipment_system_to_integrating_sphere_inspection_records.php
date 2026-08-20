<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The system a measurement was taken on is recorded the same way the sample and
     * the devices already are: a nullable link to the live ledger row plus a snapshot
     * of the code it carried at the time.
     *
     * Both columns stay nullable. Records written before the system code existed keep
     * null in them, and a record whose system is later deleted keeps the snapshot on
     * its own once the foreign key is cleared.
     *
     * Explicit index names keep every identifier inside the 64 character limit MySQL
     * enforces, matching the naming the table already uses.
     */
    public function up(): void
    {
        Schema::table('integrating_sphere_inspection_records', function (Blueprint $table): void {
            $table->foreignId('equipment_system_id')
                ->nullable()
                ->after('sample_no')
                ->constrained('equipment_systems', indexName: 'isi_records_system_id_foreign')
                ->nullOnDelete();
            $table->string('system_code')->nullable()->after('equipment_system_id');

            $table->index('system_code', 'isi_records_system_code_index');
        });
    }

    /**
     * The two drivers disagree on how a foreign key is named away.
     *
     * SQLite rebuilds the table and only accepts the column, while MySQL needs the
     * constraint name — and it has to be the explicit one from `up()`, because the
     * conventional `integrating_sphere_inspection_records_equipment_system_id_foreign`
     * is 65 characters, one past the limit, which is why it was named explicitly in
     * the first place. `dropConstrainedForeignId` would ask for that overlong name.
     */
    public function down(): void
    {
        $droppedByColumn = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('integrating_sphere_inspection_records', function (Blueprint $table) use ($droppedByColumn): void {
            $table->dropIndex('isi_records_system_code_index');
            $table->dropForeign($droppedByColumn ? ['equipment_system_id'] : 'isi_records_system_id_foreign');
            $table->dropColumn(['system_code', 'equipment_system_id']);
        });
    }
};

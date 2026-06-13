<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temp_humidity_records', function (Blueprint $table): void {
            $table->foreignId('equipment_id')->nullable()->after('id')->constrained('equipment')->nullOnDelete();
        });

        DB::table('temp_humidity_records')
            ->whereNull('equipment_id')
            ->whereNotNull('equip_no')
            ->orderBy('id')
            ->select(['id', 'equip_no'])
            ->chunkById(500, function ($records): void {
                $equipmentByNo = DB::table('equipment')
                    ->whereIn('equipment_no', $records->pluck('equip_no')->filter()->unique()->values())
                    ->pluck('id', 'equipment_no');

                foreach ($records as $record) {
                    $equipmentId = $equipmentByNo[$record->equip_no] ?? null;

                    if ($equipmentId) {
                        DB::table('temp_humidity_records')
                            ->where('id', $record->id)
                            ->update(['equipment_id' => $equipmentId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('temp_humidity_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('equipment_id');
        });
    }
};

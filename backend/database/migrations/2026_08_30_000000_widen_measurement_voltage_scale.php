<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The four photometric record forms print the supply voltage to two decimals,
 * but the columns were created as decimal(9,1), so `220.05` was silently
 * rounded to `220.1` on the way in. Widening to decimal(10,2) keeps the same
 * eight integer digits and stores the second decimal the paper form promises.
 */
return new class extends Migration
{
    private const TABLES = [
        'integrating_sphere_inspection_records',
        'integrating_sphere_calibration_records',
        'photometric_curve_inspection_records',
        'photometric_curve_calibration_records',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->decimal('voltage', 10, 2)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->decimal('voltage', 9, 1)->change();
            });
        }
    }
};

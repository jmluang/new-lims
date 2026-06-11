<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table): void {
            $table->string('measurement_range')->nullable()->after('model');
            $table->string('accuracy')->nullable()->after('measurement_range');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table): void {
            $table->dropColumn(['measurement_range', 'accuracy']);
        });
    }
};

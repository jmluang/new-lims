<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table): void {
            $table->string('rated_current')->nullable()->after('input_voltage');
            $table->string('rated_frequency')->nullable()->after('power');
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table): void {
            $table->dropColumn(['rated_current', 'rated_frequency']);
        });
    }
};

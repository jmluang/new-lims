<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lm79_spectrum_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('lm79_reports')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->double('wavelength');
            $table->double('relative_power');
            $table->unique(['report_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lm79_spectrum_points');
    }
};

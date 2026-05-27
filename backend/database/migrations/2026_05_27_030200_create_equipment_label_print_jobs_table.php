<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_label_print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->json('equipment_ids');
            $table->unsignedInteger('label_width_mm');
            $table->unsignedInteger('label_height_mm');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_label_print_jobs');
    }
};

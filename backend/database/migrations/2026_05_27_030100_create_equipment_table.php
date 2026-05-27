<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table): void {
            $table->id();
            $table->string('equipment_no')->unique();
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_no')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('equipment_locations')->nullOnDelete();
            $table->string('legacy_placement')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('enable_date')->nullable();
            $table->date('calibration_date')->nullable();
            $table->string('calibration_duration')->nullable();
            $table->date('next_calibration_date')->nullable();
            $table->string('status')->default('active');
            $table->string('device_image')->nullable();
            $table->json('manual_files')->nullable();
            $table->json('instruction_files')->nullable();
            $table->json('calibration_files')->nullable();
            $table->json('other_files')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};

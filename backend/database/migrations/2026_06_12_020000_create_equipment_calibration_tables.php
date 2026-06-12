<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calibration_project_id')->nullable()->constrained('calibration_projects')->nullOnDelete();
            $table->string('calibration_name');
            $table->timestamp('calibration_time');
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name')->nullable();
            $table->string('result')->default('qualified');
            $table->text('remark')->nullable();
            $table->json('attachment_files')->nullable();
            $table->json('photo_files')->nullable();
            $table->timestamps();

            $table->index('calibration_time');
            $table->index('result');
        });

        Schema::create('equipment_calibration_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_calibration_id')->constrained('equipment_calibrations')->cascadeOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->string('equipment_no');
            $table->string('equipment_name');
            $table->string('equipment_model')->nullable();
            $table->date('calibration_date')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('equipment_calibration_standards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_calibration_id')->constrained('equipment_calibrations')->cascadeOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->string('standard_no');
            $table->string('standard_name');
            $table->string('standard_model')->nullable();
            $table->date('calibration_date')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_calibration_standards');
        Schema::dropIfExists('equipment_calibration_devices');
        Schema::dropIfExists('equipment_calibrations');
    }
};

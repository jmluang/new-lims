<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrating_sphere_calibration_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('standard_equipment_id')
                ->nullable()
                ->constrained('equipment', indexName: 'isc_records_standard_id_foreign')
                ->nullOnDelete();
            $table->string('standard_no');
            $table->string('standard_name');
            $table->string('standard_manufacturer')->nullable();
            $table->string('standard_model')->nullable();
            $table->string('standard_serial_no')->nullable();
            $table->date('standard_next_calibration_date')->nullable();
            $table->foreignId('equipment_system_id')
                ->nullable()
                ->constrained('equipment_systems', indexName: 'isc_records_system_id_foreign')
                ->nullOnDelete();
            $table->string('system_code');
            $table->string('system_name')->nullable();
            $table->string('mode_code');
            $table->string('mode_label');
            $table->string('sensitivity_code');
            $table->string('sensitivity_label');
            $table->integer('color_temperature');
            $table->decimal('color_rendering_index', 5, 1);
            $table->decimal('luminous_flux', 12, 1);
            $table->decimal('voltage', 9, 1);
            $table->decimal('current', 12, 4);
            $table->decimal('power', 12, 4);
            $table->decimal('power_factor', 6, 4);
            $table->integer('frequency');
            $table->text('remark')->nullable();
            $table->timestamp('recorded_at');
            $table->foreignId('operator_id')
                ->nullable()
                ->constrained('users', indexName: 'isc_records_operator_id_foreign')
                ->nullOnDelete();
            $table->string('operator_name')->nullable();
            $table->timestamps();

            $table->index('recorded_at', 'isc_records_recorded_at_index');
            $table->index('standard_no', 'isc_records_standard_no_index');
            $table->index('system_code', 'isc_records_system_code_index');
            $table->index('mode_code', 'isc_records_mode_code_index');
            $table->index('sensitivity_code', 'isc_records_sensitivity_code_index');
            $table->index(['standard_equipment_id', 'recorded_at'], 'isc_records_std_recorded_index');
        });

        Schema::create('integrating_sphere_calibration_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calibration_record_id')
                ->constrained('integrating_sphere_calibration_records', indexName: 'isc_equipment_record_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('equipment_id')
                ->nullable()
                ->constrained('equipment', indexName: 'isc_equipment_equipment_id_foreign')
                ->nullOnDelete();
            $table->string('equipment_no');
            $table->string('equipment_name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_no')->nullable();
            $table->date('next_calibration_date')->nullable();
            $table->timestamps();

            $table->unique(['calibration_record_id', 'equipment_id'], 'isc_equipment_record_equipment_unique');
            $table->index('equipment_no', 'isc_equipment_equipment_no_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrating_sphere_calibration_equipment');
        Schema::dropIfExists('integrating_sphere_calibration_records');
    }
};

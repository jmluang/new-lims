<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photometric_curve_calibration_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('standard_equipment_id')
                ->nullable()
                ->constrained('equipment', indexName: 'pcc_records_standard_id_foreign')
                ->nullOnDelete();
            $table->string('standard_no');
            $table->string('standard_name');
            $table->string('standard_manufacturer')->nullable();
            $table->string('standard_model')->nullable();
            $table->string('standard_serial_no')->nullable();
            $table->date('standard_next_calibration_date')->nullable();
            $table->foreignId('equipment_system_id')
                ->nullable()
                ->constrained('equipment_systems', indexName: 'pcc_records_system_id_foreign')
                ->nullOnDelete();
            $table->string('system_code');
            $table->string('system_name')->nullable();
            $table->string('probe');
            $table->decimal('test_distance', 12, 4);
            $table->decimal('calibration_coefficient', 12, 4);
            $table->decimal('peak_luminous_intensity', 12, 1);
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
                ->constrained('users', indexName: 'pcc_records_operator_id_foreign')
                ->nullOnDelete();
            $table->string('operator_name')->nullable();
            $table->timestamps();

            $table->index('recorded_at', 'pcc_records_recorded_at_index');
            $table->index('standard_no', 'pcc_records_standard_no_index');
            $table->index('system_code', 'pcc_records_system_code_index');
            // The probe filter is always read newest-first, and a probe-only lookup uses
            // this index's leftmost column, so a standalone probe index would only add
            // write cost.
            $table->index(['probe', 'recorded_at'], 'pcc_records_probe_recorded_index');
            $table->index(['standard_equipment_id', 'recorded_at'], 'pcc_records_std_recorded_index');
        });

        Schema::create('photometric_curve_calibration_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calibration_record_id')
                ->constrained('photometric_curve_calibration_records', indexName: 'pcc_equipment_record_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('equipment_id')
                ->nullable()
                ->constrained('equipment', indexName: 'pcc_equipment_equipment_id_foreign')
                ->nullOnDelete();
            $table->string('equipment_no');
            $table->string('equipment_name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_no')->nullable();
            $table->date('next_calibration_date')->nullable();
            $table->timestamps();

            $table->unique(['calibration_record_id', 'equipment_id'], 'pcc_equipment_record_equipment_unique');
            $table->index('equipment_no', 'pcc_equipment_equipment_no_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photometric_curve_calibration_equipment');
        Schema::dropIfExists('photometric_curve_calibration_records');
    }
};

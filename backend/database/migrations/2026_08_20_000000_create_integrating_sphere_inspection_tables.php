<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrating_sphere_inspection_records', function (Blueprint $table): void {
            $table->id();
            // The ledger link stays nullable on purpose: deleting a sample must not
            // erase the inspection evidence, so the snapshot columns below carry the
            // canonical values that the record was measured against.
            $table->foreignId('sample_id')->nullable()->constrained('samples')->nullOnDelete();
            $table->string('sample_no');
            $table->decimal('chromaticity_x', 6, 4);
            $table->decimal('chromaticity_y', 6, 4);
            $table->decimal('dominant_wavelength', 7, 1);
            $table->decimal('peak_wavelength', 7, 1);
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
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name')->nullable();
            $table->timestamps();

            $table->index('recorded_at', 'isi_records_recorded_at_index');
            $table->index('sample_no', 'isi_records_sample_no_index');
            $table->index(['sample_id', 'recorded_at'], 'isi_records_sample_recorded_index');
        });

        // Explicit index names keep every identifier inside the 64 character limit
        // MySQL enforces; the generated names for these table/column pairs overflow it.
        Schema::create('integrating_sphere_inspection_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_record_id')
                ->constrained('integrating_sphere_inspection_records', indexName: 'isi_equipment_record_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('equipment_id')
                ->nullable()
                ->constrained('equipment', indexName: 'isi_equipment_equipment_id_foreign')
                ->nullOnDelete();
            $table->string('equipment_no');
            $table->string('equipment_name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_no')->nullable();
            $table->date('next_calibration_date')->nullable();
            $table->timestamps();

            $table->unique(['inspection_record_id', 'equipment_id'], 'isi_equipment_record_equipment_unique');
            $table->index('equipment_no', 'isi_equipment_equipment_no_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrating_sphere_inspection_equipment');
        Schema::dropIfExists('integrating_sphere_inspection_records');
    }
};

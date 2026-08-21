<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The photometric-curve inspection is its own aggregate rather than extra nullable
     * columns on the integrating-sphere record: the two workbooks measure different
     * quantities, and dedicated typed columns keep validation, indexing and audit
     * evidence readable instead of hiding the contract behind a generic value bag.
     *
     * Explicit index names keep every identifier inside the 64 character limit MySQL
     * enforces; the generated names for these table/column pairs overflow it.
     */
    public function up(): void
    {
        Schema::create('photometric_curve_inspection_records', function (Blueprint $table): void {
            $table->id();
            // Every ledger link stays nullable on purpose: deleting a sample, a system
            // or an operator must not erase the inspection evidence, so the snapshot
            // columns beside them carry the values the record was measured against.
            $table->foreignId('sample_id')->nullable()->constrained('samples')->nullOnDelete();
            $table->string('sample_no');
            $table->foreignId('equipment_system_id')
                ->nullable()
                ->constrained('equipment_systems', indexName: 'pci_records_system_id_foreign')
                ->nullOnDelete();
            $table->string('system_code')->nullable();
            $table->string('system_name')->nullable();
            $table->decimal('c0_180', 5, 1);
            $table->decimal('c30_210', 5, 1);
            $table->decimal('c60_240', 5, 1);
            $table->decimal('c90_270', 5, 1);
            // The average angle is deliberately absent: it is derived from the four
            // columns above on every read, which removes the workbook's failure mode
            // of a hand-typed average drifting away from the angles it summarises.
            $table->string('probe');
            $table->decimal('test_distance', 12, 4);
            $table->decimal('peak_luminous_intensity', 12, 1);
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

            $table->index('recorded_at', 'pci_records_recorded_at_index');
            $table->index('sample_no', 'pci_records_sample_no_index');
            $table->index('system_code', 'pci_records_system_code_index');
            $table->index(['sample_id', 'recorded_at'], 'pci_records_sample_recorded_index');
            $table->index(['equipment_system_id', 'recorded_at'], 'pci_records_system_recorded_index');
        });

        Schema::create('photometric_curve_inspection_equipment', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_record_id')
                ->constrained('photometric_curve_inspection_records', indexName: 'pci_equipment_record_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('equipment_id')
                ->nullable()
                ->constrained('equipment', indexName: 'pci_equipment_equipment_id_foreign')
                ->nullOnDelete();
            $table->string('equipment_no');
            $table->string('equipment_name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_no')->nullable();
            $table->date('next_calibration_date')->nullable();
            $table->timestamps();

            $table->unique(['inspection_record_id', 'equipment_id'], 'pci_equipment_record_equipment_unique');
            $table->index('equipment_no', 'pci_equipment_equipment_no_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photometric_curve_inspection_equipment');
        Schema::dropIfExists('photometric_curve_inspection_records');
    }
};

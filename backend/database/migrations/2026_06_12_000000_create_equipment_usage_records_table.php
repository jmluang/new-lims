<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('sample_id')->constrained('samples')->cascadeOnDelete();
            $table->string('equipment_no');
            $table->string('equipment_name');
            $table->string('sample_no');
            $table->string('sample_name');
            $table->string('sample_model')->nullable();
            $table->timestamp('start_time');
            $table->timestamp('end_time')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index(['equipment_id', 'start_time']);
            $table->index(['sample_id', 'start_time']);
            $table->index('end_time');
            $table->index('equipment_no');
            $table->index('sample_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_usage_records');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_order_id')->constrained('test_orders')->cascadeOnDelete();
            $table->foreignId('test_order_sample_id')->nullable()->constrained('test_order_samples')->nullOnDelete();
            $table->unsignedInteger('delivery_sequence')->default(1);
            $table->string('sample_no')->unique();
            $table->string('sample_name');
            $table->string('specification')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status')->default('pending');
            $table->string('current_holder')->nullable();
            $table->string('current_location')->nullable();
            $table->string('storage_condition')->nullable();
            $table->date('received_date')->nullable();
            $table->text('appearance_check')->nullable();
            $table->string('batch_no')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('delivery_received_count')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('test_order_id');
            $table->index('test_order_sample_id');
            $table->index('status');
            $table->index('current_holder');
        });

        Schema::create('sample_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sample_id')->constrained('samples')->cascadeOnDelete();
            $table->string('action_type');
            $table->foreignId('action_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('action_time');
            $table->string('holder_from')->nullable();
            $table->string('holder_to')->nullable();
            $table->string('location_from')->nullable();
            $table->string('location_to')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index(['sample_id', 'action_time']);
            $table->index('action_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_flows');
        Schema::dropIfExists('samples');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_order_sequences', function (Blueprint $table): void {
            $table->date('date_key')->primary();
            $table->unsignedInteger('last_no')->default(0);
            $table->timestamps();
        });

        Schema::create('test_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no')->unique();
            $table->string('contract_no')->unique();
            $table->date('order_date');
            $table->date('planned_end_date')->nullable();
            $table->string('urgency')->default('normal');
            $table->foreignId('client_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('client_company');
            $table->string('client_address')->nullable();
            $table->string('client_contact')->nullable();
            $table->string('client_phone', 64)->nullable();
            $table->foreignId('manufacturer_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('manufacturer_company')->nullable();
            $table->string('manufacturer_address')->nullable();
            $table->string('manufacturer_contact')->nullable();
            $table->string('manufacturer_phone', 64)->nullable();
            $table->foreignId('maker_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('maker_company')->nullable();
            $table->string('maker_address')->nullable();
            $table->string('maker_contact')->nullable();
            $table->string('maker_phone', 64)->nullable();
            $table->json('report_forms')->nullable();
            $table->string('delivery_method')->nullable();
            $table->string('outsourcing_option')->nullable();
            $table->text('remark')->nullable();
            $table->string('sample_status')->default('not_received');
            $table->string('address_lab_name')->nullable();
            $table->string('address_contact')->nullable();
            $table->string('address_detail')->nullable();
            $table->string('address_phone', 64)->nullable();
            $table->string('client_signature')->nullable();
            $table->date('client_sign_date')->nullable();
            $table->string('dept_confirm')->nullable();
            $table->date('dept_confirm_date')->nullable();
            $table->string('lab_confirm')->nullable();
            $table->date('lab_confirm_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_date');
            $table->index('client_company');
            $table->index('sample_status');
            $table->index('client_customer_id');
        });

        Schema::create('test_order_standards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_order_id')->constrained('test_orders')->cascadeOnDelete();
            $table->foreignId('standard_id')->nullable()->constrained('standards')->nullOnDelete();
            $table->string('standard_code');
            $table->string('standard_name');
            $table->string('report_language')->nullable();
            $table->json('qualifications')->nullable();
            $table->text('requirement')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('test_order_id');
        });

        Schema::create('test_order_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_order_id')->constrained('test_orders')->cascadeOnDelete();
            $table->string('sample_name');
            $table->string('specification')->nullable();
            $table->string('model')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('quantity')->default(1);
            $table->text('detail_content')->nullable();
            $table->text('remark')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('test_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_order_samples');
        Schema::dropIfExists('test_order_standards');
        Schema::dropIfExists('test_orders');
        Schema::dropIfExists('test_order_sequences');
    }
};

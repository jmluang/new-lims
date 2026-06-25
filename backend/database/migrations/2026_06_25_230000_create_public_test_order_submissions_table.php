<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_test_order_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('submission_no')->unique();
            $table->foreignId('matched_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('test_order_id')->nullable()->constrained('test_orders')->nullOnDelete();
            $table->string('client_company');
            $table->string('client_address')->nullable();
            $table->string('client_contact')->nullable();
            $table->string('client_phone', 64);
            $table->json('samples');
            $table->string('status')->default('pending');
            $table->text('review_remark')->nullable();
            $table->string('submitted_ip', 64)->nullable();
            $table->text('submitted_user_agent')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('client_phone');
            $table->index('matched_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_test_order_submissions');
    }
};

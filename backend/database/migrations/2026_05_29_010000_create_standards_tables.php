<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standards', function (Blueprint $table): void {
            $table->id();
            $table->string('std_no')->unique();
            $table->string('chinese_name');
            $table->date('publish_date')->nullable();
            $table->date('implement_date')->nullable();
            $table->string('status')->default('active');
            $table->date('abolish_date')->nullable();
            $table->string('replaced_by')->nullable();
            $table->string('corresponding_std', 500)->nullable();
            $table->string('category')->nullable();
            $table->string('language', 32)->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('chinese_name');
            $table->index('status');
            $table->index('category');
            $table->index('language');
        });

        Schema::create('standard_catalogs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('standard_id')->constrained('standards')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('standard_catalogs')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('content')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['standard_id', 'code']);
            $table->index(['standard_id', 'parent_id']);
        });

        Schema::create('standard_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('standard_id')->constrained('standards')->cascadeOnDelete();
            $table->string('item_no');
            $table->string('item_name');
            $table->text('requirement')->nullable();
            $table->string('unit', 64)->nullable();
            $table->string('method')->nullable();
            $table->text('remark')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['standard_id', 'item_no']);
            $table->index(['standard_id', 'item_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_items');
        Schema::dropIfExists('standard_catalogs');
        Schema::dropIfExists('standards');
    }
};

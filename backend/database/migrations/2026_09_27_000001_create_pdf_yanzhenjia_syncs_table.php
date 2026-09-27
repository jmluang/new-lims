<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_yanzhenjia_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pdf_file_id')->unique()->constrained('pdf_files')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('remote_file_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_yanzhenjia_syncs');
    }
};

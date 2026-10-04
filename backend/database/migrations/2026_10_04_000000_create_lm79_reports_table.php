<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lm79_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sample_id')->nullable()->constrained()->nullOnDelete();
            $table->json('sample_snapshot');
            $table->string('report_number', 128);
            $table->string('normalized_report_number', 128)->unique();
            $table->json('data');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pdf_document_id')->nullable()->constrained('pdf_documents')->restrictOnDelete();
            $table->uuid('pdf_source_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lm79_reports');
    }
};

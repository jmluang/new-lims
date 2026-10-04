<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lm79_report_sequences', function (Blueprint $table): void {
            $table->date('date_key')->primary();
            $table->unsignedBigInteger('last_no')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lm79_report_sequences');
    }
};

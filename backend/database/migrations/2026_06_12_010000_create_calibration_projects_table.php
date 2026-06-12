<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('project_no')->unique();
            $table->string('project_name');
            $table->string('status')->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('remark')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_projects');
    }
};

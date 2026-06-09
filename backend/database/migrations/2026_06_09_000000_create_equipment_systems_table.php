<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_systems', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::table('equipment', function (Blueprint $table): void {
            $table->foreignId('system_id')
                ->nullable()
                ->after('location_id')
                ->constrained('equipment_systems')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('system_id');
        });

        Schema::dropIfExists('equipment_systems');
    }
};

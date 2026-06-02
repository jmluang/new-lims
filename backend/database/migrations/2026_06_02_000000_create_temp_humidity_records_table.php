<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temp_humidity_records', function (Blueprint $table): void {
            $table->id();
            $table->string('equip_no')->nullable()->index();
            $table->decimal('temperature', 6, 2)->nullable();
            $table->decimal('humidity', 6, 2)->nullable();
            $table->string('location_site')->nullable();
            $table->string('location_room')->nullable();
            $table->string('record_person')->nullable();
            $table->text('remark')->nullable();
            $table->timestamp('record_time')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temp_humidity_records');
    }
};

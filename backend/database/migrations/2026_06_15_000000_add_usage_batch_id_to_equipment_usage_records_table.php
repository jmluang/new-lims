<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_usage_records', function (Blueprint $table): void {
            $table->uuid('usage_batch_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('equipment_usage_records', function (Blueprint $table): void {
            $table->dropIndex(['usage_batch_id']);
            $table->dropColumn('usage_batch_id');
        });
    }
};

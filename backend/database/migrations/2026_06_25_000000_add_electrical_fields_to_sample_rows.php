<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_order_samples', function (Blueprint $table): void {
            $table->string('input_voltage')->nullable()->after('model');
            $table->string('power')->nullable()->after('input_voltage');
        });

        Schema::table('samples', function (Blueprint $table): void {
            $table->string('input_voltage')->nullable()->after('model');
            $table->string('power')->nullable()->after('input_voltage');
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table): void {
            $table->dropColumn(['input_voltage', 'power']);
        });

        Schema::table('test_order_samples', function (Blueprint $table): void {
            $table->dropColumn(['input_voltage', 'power']);
        });
    }
};

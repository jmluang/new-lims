<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_orders', function (Blueprint $table): void {
            $table->string('client_email')->nullable()->after('client_phone');
            $table->string('manufacturer_email')->nullable()->after('manufacturer_phone');
            $table->string('maker_email')->nullable()->after('maker_phone');
            $table->string('sample_return')->nullable()->after('report_forms');
            $table->text('shipping_notes')->nullable()->after('address_phone');
        });

        Schema::table('test_order_samples', function (Blueprint $table): void {
            $table->string('rated_current')->nullable()->after('input_voltage');
            $table->string('rated_frequency')->nullable()->after('power');
            $table->string('quantity_unit', 32)->nullable()->after('quantity');
            $table->string('sample_condition')->nullable()->after('status');
            $table->text('sample_condition_note')->nullable()->after('sample_condition');
        });
    }

    public function down(): void
    {
        Schema::table('test_order_samples', function (Blueprint $table): void {
            $table->dropColumn([
                'rated_current',
                'rated_frequency',
                'quantity_unit',
                'sample_condition',
                'sample_condition_note',
            ]);
        });

        Schema::table('test_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'client_email',
                'manufacturer_email',
                'maker_email',
                'sample_return',
                'shipping_notes',
            ]);
        });
    }
};

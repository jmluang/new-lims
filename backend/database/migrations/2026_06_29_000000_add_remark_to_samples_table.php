<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('samples', function (Blueprint $table): void {
            $table->text('remark')->nullable()->after('appearance_check');
        });
    }

    public function down(): void
    {
        Schema::table('samples', function (Blueprint $table): void {
            $table->dropColumn('remark');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('guard_name');
            $table->boolean('is_system')->default(false)->after('description');
            $table->string('status')->default('active')->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['description', 'is_system', 'status']);
        });
    }
};

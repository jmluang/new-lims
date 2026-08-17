<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a message wants to take you.
 *
 * Test order messages already carry test_order_id and the message centre knows
 * to navigate with it. Adding a column per feature does not scale, so this
 * holds an in-app path instead — always a path, never a URL, so a stored value
 * can never redirect a reader off the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_messages', function (Blueprint $table): void {
            $table->string('link_path', 512)->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('user_messages', function (Blueprint $table): void {
            $table->dropColumn('link_path');
        });
    }
};

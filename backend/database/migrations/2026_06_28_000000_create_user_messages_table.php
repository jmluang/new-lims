<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('test_order_id')->nullable()->constrained('test_orders')->nullOnDelete();
            $table->string('title');
            $table->text('content');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_user_id', 'read_at']);
            $table->index(['recipient_user_id', 'created_at']);
            $table->index('test_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_messages');
    }
};

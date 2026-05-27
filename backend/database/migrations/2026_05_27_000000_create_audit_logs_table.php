<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 64);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_name_snapshot')->nullable();
            $table->string('action');
            $table->string('module');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->json('changed_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at');

            $table->index('request_id');
            $table->index(['module', 'action']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('actor_user_id');
            $table->unique('hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionary_sets', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('dictionary_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dictionary_set_id')->constrained('dictionary_sets')->cascadeOnDelete();
            $table->string('label');
            $table->string('value');
            $table->string('color')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['dictionary_set_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_items');
        Schema::dropIfExists('dictionary_sets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            foreach (['type', 'level', 'source', 'industry'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('dictionary_items');
        Schema::dropIfExists('dictionary_sets');
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'type')) {
                $table->string('type')->nullable();
            }
            if (! Schema::hasColumn('customers', 'level')) {
                $table->string('level')->nullable();
            }
            if (! Schema::hasColumn('customers', 'source')) {
                $table->string('source')->nullable();
            }
            if (! Schema::hasColumn('customers', 'industry')) {
                $table->string('industry')->nullable();
            }
        });

        if (! Schema::hasTable('dictionary_sets')) {
            Schema::create('dictionary_sets', function (Blueprint $table): void {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('dictionary_items')) {
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
    }
};

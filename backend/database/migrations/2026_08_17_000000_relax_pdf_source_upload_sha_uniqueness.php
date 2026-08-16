<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A SHA-256 says "these bytes are identical", not "this is the same business
 * document". Making it globally unique meant one abandoned or failed upload
 * blocked those exact bytes forever, for every user and every report number.
 * Business identity stays where it belongs: the report number on the document,
 * and the idempotency key on the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_source_uploads', function (Blueprint $table): void {
            $table->dropUnique('pdf_source_uploads_sha256_unique');
            $table->index('sha256', 'pdf_source_uploads_sha256_index');
        });
    }

    public function down(): void
    {
        Schema::table('pdf_source_uploads', function (Blueprint $table): void {
            $table->dropIndex('pdf_source_uploads_sha256_index');
            $table->unique('sha256', 'pdf_source_uploads_sha256_unique');
        });
    }
};

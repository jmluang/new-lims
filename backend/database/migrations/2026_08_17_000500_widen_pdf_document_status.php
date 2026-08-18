<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A terminal operation state is copied verbatim onto the document, so the
 * column has to be able to hold whatever `pdf_signing_operations.state` holds.
 *
 * At varchar(16) it could not: `irreversible_failed` is 19 characters, so the
 * one write that records a signature failing after the private key ran threw
 * SQLSTATE[22001] and rolled back. The failure that most needs recording was
 * the single failure that could not be recorded. Matching the source column
 * removes the whole class rather than the three characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table): void {
            $table->string('status', 24)->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::table('pdf_documents', function (Blueprint $table): void {
            $table->string('status', 16)->default('draft')->change();
        });
    }
};

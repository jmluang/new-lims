<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_yanzhenjia_settings', function (Blueprint $table): void {
            $table->tinyIncrements('id');
            $table->boolean('enabled')->default(false);
            $table->string('appid', 32)->nullable();
            $table->text('secret')->nullable();
            $table->timestamps();
        });

        Schema::table('pdf_yanzhenjia_syncs', function (Blueprint $table): void {
            $table->dropUnique('pdf_yanzhenjia_syncs_pdf_file_id_unique');
            $table->string('api_version', 16)->default('legacy');
            $table->string('target_appid', 32)->nullable();
            $table->json('request_payload')->nullable();
            $table->unique(['pdf_file_id', 'api_version'], 'pdf_yanzhenjia_sync_file_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pdf_yanzhenjia_syncs', function (Blueprint $table): void {
            $table->dropUnique('pdf_yanzhenjia_sync_file_version_unique');
            $table->dropColumn(['api_version', 'target_appid', 'request_payload']);
            $table->unique('pdf_file_id');
        });

        Schema::dropIfExists('pdf_yanzhenjia_settings');
    }
};

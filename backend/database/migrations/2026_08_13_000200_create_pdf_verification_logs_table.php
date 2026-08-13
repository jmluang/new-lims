<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_verification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->comment('验证用户ID');
            $table->string('file_name', 500)->comment('文件名');
            $table->unsignedBigInteger('file_size')->comment('文件大小(字节)');
            $table->string('primary_hash', 64)->nullable()->comment('主要哈希(SHA256)');
            $table->string('secondary_hash', 64)->nullable()->comment('备选哈希(SHA3-256)');
            $table->string('md5_hash', 32)->nullable()->comment('MD5哈希');
            $table->string('crc32_hash', 16)->nullable()->comment('CRC32哈希');
            $table->boolean('overall_valid')->comment('整体验证结果');
            $table->string('security_level', 50)->default('unknown')->comment('安全级别');
            $table->text('verification_message')->comment('验证消息');
            $table->json('verification_data')->nullable()->comment('完整验证数据');
            $table->string('verify_source', 50)->nullable()->comment('验证来源: admin / public');
            $table->string('ip_address', 100)->nullable()->comment('IP地址');
            $table->string('user_agent', 1000)->nullable()->comment('用户代理');
            $table->string('saved_file_path')->nullable()->comment('保存的被验证文件路径');
            $table->timestamps();

            $table->index('file_name');
            $table->index('primary_hash');
            $table->index('overall_valid');
            $table->index('verify_source');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_verification_logs');
    }
};

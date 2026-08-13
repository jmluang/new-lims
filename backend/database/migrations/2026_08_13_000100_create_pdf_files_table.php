<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger of every signed PDF. Verification matches an uploaded file against
 * `sha256_hash` + `md5_hash` + `file_size`, so those three columns are the
 * tamper-detection contract and must stay indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_files', function (Blueprint $table): void {
            $table->id();
            $table->string('file_id')->unique()->comment('文件唯一标识');
            $table->string('file_name')->comment('原始文件名');
            $table->string('file_path')->nullable()->comment('文件存储路径');
            $table->char('sha256_hash', 64)->comment('PDF 摘要指纹');
            $table->string('md5_hash', 32)->nullable()->comment('MD5 摘要指纹');
            $table->string('cover_report_number')->nullable()->comment('封面报告编号');
            $table->text('signature')->nullable()->comment('签名字段内容');
            $table->text('public_key')->nullable()->comment('对应的公钥');
            $table->unsignedBigInteger('file_size')->nullable()->comment('文件大小(字节)');
            $table->timestamp('signed_at')->comment('签名时间');
            $table->string('created_by')->comment('操作者');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable()->comment('其他元数据');
            $table->timestamps();

            $table->index('sha256_hash');
            $table->index('md5_hash');
            $table->index('cover_report_number');
            $table->index('created_by');
            $table->index('signed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_files');
    }
};

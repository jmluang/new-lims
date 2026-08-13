<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuration tables for the PDF tamper-proof system, ported from zs-lims.
 *
 * zs-lims also carried an `rsa_keys` catalogue that signing configurations
 * pointed at. It is deliberately not migrated: the Java signer loads its PKCS#12
 * material from `DEFAULT_PFX_PATH` and ignores the key id it is handed, so the
 * table never influenced a signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_signatures', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->comment('签章名称');
            $table->string('appearance_image_path')->comment('签章图片路径');
            $table->text('description')->nullable();
            $table->string('signature_contact')->nullable()->comment('签名联系人');
            $table->string('signature_location')->nullable()->comment('签名地点');
            $table->string('signature_reason')->nullable()->comment('签名原因');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'is_default']);
        });

        Schema::create('perforation_stamps', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->comment('骑缝章名称');
            $table->string('appearance_image_path')->comment('骑缝章图片路径');
            $table->text('description')->nullable();
            $table->string('signature_contact')->nullable()->comment('签名联系人');
            $table->string('signature_location')->nullable()->comment('签名地点');
            $table->string('signature_reason')->nullable()->comment('签名原因');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'is_default']);
        });

        Schema::create('homepage_function_stamps', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->comment('功能章名称');
            $table->string('image_path')->comment('图片路径');
            $table->boolean('is_default')->default(false)->comment('是否默认');
            $table->boolean('is_active')->default(true)->comment('是否激活');
            $table->integer('sort_order')->default(0)->comment('排序顺序');
            $table->timestamps();

            $table->index(['is_active', 'is_default']);
            $table->index('sort_order');
        });

        Schema::create('certificate_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->comment('证书模板名称');
            $table->string('language', 16)->default('zh')->comment('语言');
            $table->text('description')->nullable();
            $table->string('file_name')->comment('原始文件名');
            $table->string('file_path')->comment('模板 PDF 路径');
            $table->unsignedBigInteger('file_size')->nullable()->comment('文件大小(字节)');
            $table->string('mime_type')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'language']);
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_templates');
        Schema::dropIfExists('homepage_function_stamps');
        Schema::dropIfExists('perforation_stamps');
        Schema::dropIfExists('digital_signatures');
    }
};

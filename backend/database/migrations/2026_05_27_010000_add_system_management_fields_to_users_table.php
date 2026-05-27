<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('email');
            $table->foreignId('department_id')->nullable()->after('phone')->constrained('departments')->nullOnDelete();
            $table->string('status')->default('active')->after('department_id');
            $table->timestamp('password_changed_at')->nullable()->after('password');
            $table->boolean('must_change_password')->default(false)->after('password_changed_at');
            $table->timestamp('locked_at')->nullable()->after('must_change_password');
            $table->string('lock_reason')->nullable()->after('locked_at');
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('lock_reason');
            $table->timestamp('last_login_at')->nullable()->after('failed_login_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'phone',
                'status',
                'password_changed_at',
                'must_change_password',
                'locked_at',
                'lock_reason',
                'failed_login_attempts',
                'last_login_at',
            ]);
        });
    }
};

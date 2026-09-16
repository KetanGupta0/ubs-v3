<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One users table for all three audiences.
 *
 * Three separate tables were rejected: a single login form needs a single
 * identity, mobile numbers and emails must be unique across everyone, and a
 * person could genuinely be both a client and a student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile', 20)->nullable()->unique()->after('email');
            $table->timestamp('mobile_verified_at')->nullable()->after('email_verified_at');

            $table->string('role', 20)->default('student')->after('password');
            $table->string('status', 20)->default('active')->after('role');

            $table->string('avatar_path')->nullable()->after('status');
            $table->string('timezone', 64)->default('Asia/Kolkata')->after('avatar_path');
            $table->string('locale', 10)->default('en')->after('timezone');

            // Set when an admin creates an account, cleared once the person
            // chooses their own password.
            $table->boolean('must_change_password')->default(false)->after('locale');

            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            $table->softDeletes();

            $table->index(['role', 'status']);
        });

        // Password becomes nullable, because an account created through Google
        // has never had one.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role', 'status']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'mobile', 'mobile_verified_at', 'role', 'status', 'avatar_path',
                'timezone', 'locale', 'must_change_password', 'last_login_at', 'last_login_ip',
            ]);
        });
    }
};

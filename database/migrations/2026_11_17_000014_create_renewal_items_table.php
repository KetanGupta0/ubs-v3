<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renewals and expiry (CL16): the things that lapse if nobody renews them
 * (domains, hosting, SSL certificates, update windows) alongside the
 * maintenance contracts and subscriptions already kept, each reminded
 * about at 30, 14 and 3 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            // domain, hosting, ssl, update_window or other.
            $table->string('kind', 20);
            $table->string('name', 160);
            $table->string('provider', 120)->nullable();
            $table->date('expires_on');
            $table->boolean('auto_renew')->default(false);
            $table->text('notes')->nullable();
            // active, cancelled (no longer ours to watch).
            $table->string('status', 16)->default('active');
            $table->json('reminders_sent')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_on']);
            $table->index('client_id');
        });

        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->json('reminders_sent')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->dropColumn('reminders_sent');
        });

        Schema::dropIfExists('renewal_items');
    }
};

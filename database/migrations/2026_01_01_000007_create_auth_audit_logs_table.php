<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every authentication event, successful or not.
 *
 * Failures are recorded with the identifier that was tried but never the
 * credential, so the log answers "who was targeted and from where" without
 * becoming a list of guessed passwords.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('event', 60);
            $table->string('identifier')->nullable();
            $table->string('method', 30)->nullable();
            $table->boolean('succeeded')->default(true);
            $table->string('reason')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_audit_logs');
    }
};

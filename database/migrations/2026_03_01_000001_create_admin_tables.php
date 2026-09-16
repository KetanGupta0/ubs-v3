<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables the admin panel runs on.
 *
 * The audit log here is separate from `auth_audit_logs` on purpose. That one
 * answers "who tried to sign in and from where". This one answers "who changed
 * this record, and what did it say before", which is a different question asked
 * by different people at different times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('group', 60)->default('general')->index();
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('channel', 20);
            $table->string('subject')->nullable();
            $table->text('body');

            // Placeholder names this template understands, so the editor can
            // show them rather than leaving an administrator to guess.
            $table->json('variables')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 60);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();

            /*
             * Only the attributes that actually changed, as before and after.
             * Storing whole records would make the log enormous and bury the
             * one field somebody is looking for.
             */
            $table->json('changes')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Schema::create('lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('credential_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('channel', 20);
            $table->string('destination');
            $table->string('status', 20)->default('queued');
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_deliveries');
        Schema::dropIfExists('lead_notes');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('settings');
    }
};

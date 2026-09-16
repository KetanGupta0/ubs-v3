<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything around a project that keeps running after it ships: the document
 * repository, the maintenance contract and its tickets, subscriptions, and the
 * API keys a client buys for products that need them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('document_folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index(['client_id', 'project_id', 'parent_id']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('document_folders')->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('path');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable();

            // A new version is a new row pointing at the one it replaced, so
            // the history is readable and an old version is still downloadable.
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->boolean('is_current')->default(true);

            $table->string('category', 40)->nullable();
            $table->boolean('visible_to_client')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('client_viewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'project_id', 'is_current']);
        });

        Schema::create('maintenance_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 30)->unique();
            $table->string('plan', 40);
            $table->json('scope')->nullable();
            $table->json('exclusions')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('response_hours')->default(24);
            $table->unsignedSmallInteger('resolution_hours')->default(72);
            $table->unsignedSmallInteger('included_tickets')->nullable();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('billing_interval', 20)->default('yearly');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('maintenance_contracts')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 20)->unique();
            $table->string('subject');
            $table->text('body');
            $table->string('category', 40)->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('open');

            // The clock the contract promised, frozen onto the ticket, so a
            // later change to the contract cannot retrospectively rewrite
            // whether we met it.
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('satisfaction')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->nullableMorphs('subscribable');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('interval', 20)->default('yearly');
            $table->date('starts_on');
            $table->date('renews_on');
            $table->date('ends_on')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->string('status', 20)->default('active');
            $table->json('reminders_sent')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index('renews_on');
        });

        Schema::create('api_key_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('solution_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('monthly_quota')->default(10000);
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->unsignedBigInteger('price')->default(0);
            $table->string('interval', 20)->default('monthly');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('api_key_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            $table->string('label');
            $table->string('environment', 20)->default('live');

            // Only the prefix is stored in the clear. The key itself is shown
            // once, at issue, and after that we can prove which key it is
            // without being able to reproduce it.
            $table->string('key_prefix', 20)->index();
            $table->string('key_hash', 64)->unique();
            $table->string('last_four', 4)->nullable();

            $table->string('status', 20)->default('active');
            $table->unsignedInteger('quota_used')->default(0);
            $table->date('quota_period_start')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->foreignId('rotated_from_id')->nullable()->constrained('api_keys')->nullOnDelete();

            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::create('api_key_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamps();

            $table->unique(['api_key_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_usage_logs');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('api_key_plans');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('maintenance_contracts');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_folders');
    }
};

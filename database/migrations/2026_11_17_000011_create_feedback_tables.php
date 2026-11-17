<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback on the staging preview (CL13): a bug or something to change,
 * reported against the build with screenshots, linked to the milestone it
 * holds up, and followed to a fix the client confirms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('project_milestones')->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();

            // bug or change.
            $table->string('kind', 12);
            $table->string('title', 160);
            $table->text('details');
            $table->string('page_url')->nullable();
            $table->json('screenshots')->nullable();

            // new, acknowledged, in_progress, fixed, closed or declined.
            $table->string('status', 16)->default('new');
            $table->timestamp('fixed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('feedback_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The status it moved to, or null for a comment alone.
            $table->string('status', 16)->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_events');
        Schema::dropIfExists('feedback_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone acceptance (CL9).
 *
 * Each time we ask the client to accept a milestone is a row: what we said
 * was delivered, and the answer they gave. A milestone can go round more
 * than once (changes asked for, then accepted), and every round is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milestone_signoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('milestone_id')->constrained('project_milestones')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('deliverables');
            $table->text('note')->nullable();

            // accepted or rejected; null while it waits.
            $table->string('decision', 16)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decided_ip', 45)->nullable();
            $table->text('comments')->nullable();

            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('payment_request_id')->nullable()->constrained('payment_requests')->nullOnDelete();

            $table->timestamps();

            $table->index(['project_id', 'decision']);
        });

        Schema::table('project_milestones', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_milestones', function (Blueprint $table) {
            $table->dropColumn('accepted_at');
        });

        Schema::dropIfExists('milestone_signoffs');
    }
};

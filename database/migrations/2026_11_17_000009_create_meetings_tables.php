<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meetings and minutes (CL11).
 *
 * A meeting is scheduled with a client; afterwards its minutes are written
 * and published, and the client acknowledges them or says what is wrong.
 * Minutes are versions: a published version never changes, and a
 * correction is the next version, acknowledged afresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->text('agenda')->nullable();
            $table->dateTime('starts_at');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->string('link')->nullable();
            $table->string('location', 160)->nullable();
            // scheduled, held or cancelled.
            $table->string('status', 16)->default('scheduled');
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'starts_at']);
        });

        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->json('attendees');
            $table->text('body');
            // [{title, due_on}]: become "waiting on you" items when published.
            $table->json('client_actions')->nullable();
            // [{text, owner}]: what our side took away.
            $table->json('team_actions')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acknowledged_ip', 45)->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->foreignId('disputed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('dispute_note')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'version']);
        });

        Schema::table('waiting_items', function (Blueprint $table) {
            $table->foreignId('meeting_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('waiting_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meeting_id');
        });

        Schema::dropIfExists('meeting_minutes');
        Schema::dropIfExists('meetings');
    }
};

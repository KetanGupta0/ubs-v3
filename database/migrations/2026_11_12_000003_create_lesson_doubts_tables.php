<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Questions on a lesson (EXPANSION_PLAN §6): asked by a student, answered by
 * the trainer or the team, and readable by everybody on the same batch, so a
 * question asked once is answered for the whole cohort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_doubts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            // open: waiting for an answer; answered: the team has replied
            // since it last moved; hidden: taken down by the team.
            $table->string('status', 12)->default('open');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamps();

            $table->index(['lesson_id', 'batch_id']);
            $table->index(['status', 'last_activity_at']);
        });

        Schema::create('lesson_doubt_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_doubt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('from_staff')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_doubt_replies');
        Schema::dropIfExists('lesson_doubts');
    }
};

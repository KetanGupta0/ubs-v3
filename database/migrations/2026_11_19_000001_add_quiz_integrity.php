<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Making cheating on a quiz harder, and noticing it when it happens (§9.1,
 * layers 1 and 2).
 *
 * Harder: each student draws their own questions from a pool, sees the options
 * in their own order, can be shown one question at a time with no going back,
 * and nobody sees the answers until the quiz has closed for everybody. An
 * attempt belongs to the browser that started it.
 *
 * Noticed: what happened during an attempt is written down as events, with the
 * server's time, and checks run when the quiz closes. Everything recorded is
 * evidence for a person, never a verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            // Draw this many questions from the quiz's questions for each attempt. Null: all.
            $table->unsignedSmallInteger('pool_size')->nullable()->after('shuffle_questions');
            $table->boolean('shuffle_options')->default(false)->after('pool_size');
            $table->boolean('one_at_a_time')->default(false)->after('shuffle_options');
            $table->boolean('allow_back')->default(true)->after('one_at_a_time');
            // Answers and the key only once the quiz has closed for everybody.
            $table->boolean('answers_after_close')->default(false)->after('show_answers');
            $table->timestamp('integrity_checked_at')->nullable()->after('closes_at');
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            // Fixed when the attempt starts, so a refresh changes nothing.
            $table->json('question_ids')->nullable()->after('batch_id');
            $table->json('option_orders')->nullable()->after('question_ids');
            // How far through a one-at-a-time quiz the student has gone.
            $table->unsignedSmallInteger('current_index')->default(0)->after('option_orders');
            // The browser that started it, as a hash of a cookie, and where from last.
            $table->char('device_hash', 64)->nullable()->after('current_index');
            $table->string('last_ip', 45)->nullable()->after('device_hash');
            // What the checks found when the quiz closed: [{type, summary, evidence}].
            $table->json('flags')->nullable()->after('needs_review');
            $table->timestamp('flagged_at')->nullable()->after('flags');
        });

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->unsignedInteger('seconds_spent')->default(0)->after('response');
            $table->timestamp('answered_at')->nullable()->after('seconds_spent');
        });

        Schema::create('quiz_attempt_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            // How long it lasted, where that means something: away from the quiz, on a question.
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('detail')->nullable();
            $table->string('ip', 45)->nullable();
            // The server's time, when it heard. The browser's clock is not evidence.
            $table->timestamp('occurred_at');

            $table->index(['quiz_attempt_id', 'occurred_at']);
            $table->index(['quiz_attempt_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_events');

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropColumn(['seconds_spent', 'answered_at']);
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['question_ids', 'option_orders', 'current_index', 'device_hash', 'last_ip', 'flags', 'flagged_at']);
        });

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn(['pool_size', 'shuffle_options', 'one_at_a_time', 'allow_back', 'answers_after_close', 'integrity_checked_at']);
        });
    }
};

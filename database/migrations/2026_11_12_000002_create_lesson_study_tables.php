<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What a student does on a lesson besides finishing it (EXPANSION_PLAN §6).
 *
 * `lesson_visits` is when they last had it open and roughly how long they have
 * spent on it, which is what "continue where you left off" and the coarse time
 * buckets read. `lesson_notes` is their own note and bookmark, which nobody
 * else reads, staff included.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seconds_spent')->default(0);
            $table->unsignedInteger('visits')->default(0);
            $table->timestamp('first_opened_at');
            $table->timestamp('last_opened_at');
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
            $table->index(['user_id', 'last_opened_at']);
        });

        Schema::create('lesson_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->boolean('is_bookmarked')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_notes');
        Schema::dropIfExists('lesson_visits');
    }
};

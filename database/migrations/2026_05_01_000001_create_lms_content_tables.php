<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a course is made of.
 *
 * Modules hold lessons, lessons hold materials. The locking rules live on the
 * lesson rather than in a separate table, because every rule answers the same
 * question — may this student open this lesson right now — and splitting that
 * across tables makes it answerable only by joining all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Drip at the module level: everything inside opens together.
            $table->unsignedSmallInteger('unlock_after_days')->nullable();
            $table->boolean('is_published')->default(true);

            $table->timestamps();

            $table->index(['course_id', 'sort_order']);
        });

        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('slug');
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->string('video_url')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            /*
             * The four ways a lesson can be sealed. All of them are checked, so
             * a lesson can need the fee paid AND the previous lesson done AND a
             * pass mark on a quiz. A locked lesson is still listed: seeing what
             * is coming is what makes somebody finish the one they are on.
             */
            $table->unsignedSmallInteger('unlock_after_days')->nullable();
            $table->timestamp('unlock_at')->nullable();
            $table->foreignId('prerequisite_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->boolean('requires_payment')->default(false);
            $table->foreignId('required_quiz_id')->nullable();
            $table->unsignedTinyInteger('min_quiz_score')->nullable();

            $table->boolean('is_preview')->default(false);
            $table->boolean('is_published')->default(true);

            $table->timestamps();

            $table->unique(['course_id', 'slug']);
            $table->index(['course_module_id', 'sort_order']);
        });

        Schema::create('lesson_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->unsignedSmallInteger('seconds_spent')->nullable();
            $table->timestamps();

            $table->unique(['lesson_id', 'user_id']);
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('path')->nullable();
            $table->string('external_url')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            // Viewable in the browser but not downloadable, for a slide deck we
            // do not want circulating outside the cohort.
            $table->boolean('is_downloadable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['course_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
        Schema::dropIfExists('lesson_completions');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_modules');
    }
};

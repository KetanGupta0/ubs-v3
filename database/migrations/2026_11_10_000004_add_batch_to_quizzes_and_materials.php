<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A quiz or a handout for one batch of a course, rather than every batch.
 *
 * Two batches of the same course run at different speeds, sometimes with
 * different trainers; v2 set quizzes and materials per batch. Null keeps the
 * existing meaning: the whole course. Assignments already work this way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('course_module_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('lesson_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('materials', fn (Blueprint $table) => $table->dropConstrainedForeignId('batch_id'));
        Schema::table('quizzes', fn (Blueprint $table) => $table->dropConstrainedForeignId('batch_id'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment, and the paperwork that comes out of it.
 *
 * A certificate and an internship's offer letter are both documents with a
 * number and a public verification code, because a certificate nobody can check
 * is decoration. They are separate tables because an internship produces four
 * different documents at four different moments, and squeezing that into the
 * certificates table would mean a kind column and a lot of nullable fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_module_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->unsignedTinyInteger('attempts_allowed')->default(1);
            $table->unsignedTinyInteger('pass_percent')->default(50);
            $table->boolean('shuffle_questions')->default(true);

            // Whether a student may see which answers were wrong afterwards.
            $table->boolean('show_answers')->default(true);
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->boolean('is_published')->default(false);

            $table->timestamps();

            $table->index(['course_id', 'is_published']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('mcq');
            $table->text('body');
            $table->json('options')->nullable();

            // Kept out of anything sent to a student sitting the quiz.
            $table->json('correct')->nullable();
            $table->text('explanation')->nullable();

            $table->unsignedSmallInteger('marks')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['quiz_id', 'sort_order']);
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedSmallInteger('total_marks')->default(0);
            $table->decimal('percent', 5, 2)->default(0);
            $table->boolean('passed')->default(false);

            // A short answer question cannot be machine marked, so an attempt
            // containing one waits for a person.
            $table->boolean('needs_review')->default(false);

            $table->timestamps();

            $table->unique(['quiz_id', 'user_id', 'attempt_number']);
        });

        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->json('response')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->decimal('marks_awarded', 6, 2)->default(0);
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->unique(['quiz_attempt_id', 'question_id']);
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_module_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->longText('brief');
            $table->json('checklist')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->unsignedSmallInteger('max_marks')->default(100);
            $table->boolean('allow_late')->default(true);
            $table->boolean('is_project')->default(false);
            $table->boolean('is_published')->default(false);

            $table->timestamps();

            $table->index(['course_id', 'batch_id']);
        });

        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->json('files')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('demo_url')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_late')->default(false);
            $table->string('status', 20)->default('submitted');

            $table->unsignedSmallInteger('marks')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable();

            $table->timestamps();

            $table->unique(['assignment_id', 'user_id']);
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 40)->unique();

            // Public, so an employer can check it without an account. Long
            // enough that it cannot be guessed by trying numbers.
            $table->string('verification_code', 32)->unique();

            $table->timestamp('issued_at');
            $table->string('title');
            $table->decimal('final_percent', 5, 2)->nullable();
            $table->string('grade', 20)->nullable();
            $table->string('pdf_path')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'batch_id'], 'one_certificate_per_enrolment');
        });

        Schema::create('internship_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind', 30);
            $table->string('number', 40)->unique();
            $table->string('verification_code', 32)->unique();
            $table->timestamp('issued_at');
            $table->json('payload')->nullable();
            $table->string('pdf_path')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'kind'], 'one_document_of_each_kind');
        });

        Schema::create('mentor_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedTinyInteger('week_number');
            $table->date('reviewed_on');
            $table->text('summary');
            $table->text('what_went_well')->nullable();
            $table->text('to_improve')->nullable();

            // Marks against the criteria a university asks for, so the final
            // evaluation is assembled from weekly evidence rather than written
            // from memory at the end.
            $table->json('marks')->nullable();
            $table->unsignedTinyInteger('overall')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'batch_id', 'week_number'], 'one_review_per_week');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentor_reviews');
        Schema::dropIfExists('internship_documents');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('quiz_answers');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('quizzes');
    }
};

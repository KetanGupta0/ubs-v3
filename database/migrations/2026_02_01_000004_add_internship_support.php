<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internships for college students.
 *
 * An internship reuses the courses table rather than getting its own, because
 * structurally it is the same thing: a cohort, a schedule, a syllabus, a mentor
 * and assessment. What differs is why the student is there. They are usually
 * meeting a college requirement, which means the paperwork the college accepts
 * matters as much as the learning, and that is what these columns add.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            /*
             * Which documents this offering produces, for example an offer
             * letter and a completion certificate. Advertised on the public
             * page because for an intern it is often the deciding factor.
             */
            $table->json('documents_provided')->nullable()->after('tools');

            $table->string('mode', 20)->default('remote')->after('documents_provided');

            // Internships are sold by month more often than by week.
            $table->unsignedSmallInteger('duration_months')->nullable()->after('duration_weeks');

            $table->string('project_focus')->nullable()->after('mode');
        });

        Schema::create('colleges', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('short_name', 60)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('university')->nullable();

            // The person at the college who sends and chases the batch.
            $table->string('coordinator_name')->nullable();
            $table->string('coordinator_email')->nullable();
            $table->string('coordinator_mobile', 20)->nullable();

            $table->date('mou_signed_on')->nullable();
            $table->date('mou_expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->foreignId('college_id')->nullable()->after('qualification')->constrained()->nullOnDelete();
            $table->string('enrollment_number', 60)->nullable()->after('college_id');
            $table->string('course_of_study', 120)->nullable()->after('enrollment_number');
            $table->unsignedTinyInteger('current_semester')->nullable()->after('course_of_study');
            $table->unsignedSmallInteger('graduation_year')->nullable()->after('current_semester');
        });

        Schema::table('leads', function (Blueprint $table) {
            // A college enquiry is about a batch, not one person, so it carries
            // the institution and a rough headcount instead of a single name.
            $table->string('college_name')->nullable()->after('company');
            $table->unsignedSmallInteger('student_count')->nullable()->after('college_name');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['college_name', 'student_count']);
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('college_id');
            $table->dropColumn(['enrollment_number', 'course_of_study', 'current_semester', 'graduation_year']);
        });

        Schema::dropIfExists('colleges');

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['documents_provided', 'mode', 'duration_months', 'project_focus']);
        });
    }
};

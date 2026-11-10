<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Whether students may put themselves on a course right now.
 *
 * Separate from publishing: a course between batches stays on the site, with
 * its syllabus and its next date, while nobody can sign up for it. Staff can
 * still enrol people by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('is_enrollment_open')->default(true)->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('is_enrollment_open');
        });
    }
};

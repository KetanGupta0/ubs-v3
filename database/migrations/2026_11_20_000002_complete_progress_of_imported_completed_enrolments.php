<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * An enrolment v2 marked completed came across at 0% progress: v2 kept none and
 * its courses arrive with no lessons, so a finished course read "0%" beside
 * "completed". The importer now writes 100%; this puts the ones already imported
 * in step. Only enrolments brought from v2 are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('enrollments')
            ->where('status', 'completed')
            ->where('progress_percent', '<', 100)
            ->whereIn('id', DB::table('legacy_id_map')->where('source_table', 'enrollments')->select('target_id'))
            ->update(['progress_percent' => 100]);
    }

    public function down(): void
    {
        // Nothing to undo: v2 recorded no progress to go back to.
    }
};

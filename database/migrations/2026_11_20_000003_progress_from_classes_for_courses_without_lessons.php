<?php

use App\Services\Lms\Progress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A course with no lessons now reads its progress from the batch's classes held
 * (Progress::recalculateBatch). Work that out once for every batch there is, so
 * a student still studying on such a course does not sit at 0% until a class
 * next changes. A finished enrolment never goes down.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('batches')->pluck('id') as $batchId) {
            app(Progress::class)->recalculateBatch((int) $batchId);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the figures were 0 for want of lessons to count.
    }
};

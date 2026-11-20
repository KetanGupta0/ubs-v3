<?php

use App\Services\Lms\Progress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Progress on a course with no lessons is now the classes each student attended,
 * not the batch's classes held (owner's decision, 2026-11-20). Work it out again
 * for every batch. A finished, dropped or paused enrolment keeps its figure.
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
        // Nothing to undo.
    }
};

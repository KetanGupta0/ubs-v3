<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The v2 importer wrote a past class as "completed", a status a session does not
 * have (scheduled, held, cancelled), so the performance pages and the batch view,
 * which count held classes, counted none of them. Nothing else ever wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('live_sessions')->where('status', 'completed')->update(['status' => 'held']);
    }

    public function down(): void
    {
        // Not reversible: "completed" was never a status a session should have.
    }
};

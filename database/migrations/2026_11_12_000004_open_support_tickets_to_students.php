<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets belong to whoever raised them: a client or, since November 2026, a
 * student, as on the old site (decision D2). A student's ticket has no
 * contract and so no promised response time; everything else is the same
 * desk the staff already work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->renameColumn('client_id', 'requester_id');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->renameColumn('requester_id', 'client_id');
        });
    }
};

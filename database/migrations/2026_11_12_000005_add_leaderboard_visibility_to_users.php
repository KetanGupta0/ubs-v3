<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Whether a student's name shows on the boards other students see. Their
 * place is counted either way, so hiding a name does not move anybody else;
 * staff always see who is who.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('show_on_leaderboard')->default(true)->after('reminder_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('show_on_leaderboard');
        });
    }
};

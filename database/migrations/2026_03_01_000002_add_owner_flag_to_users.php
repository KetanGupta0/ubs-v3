<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separates the owner from the staff who also sign in as administrators.
 *
 * Without this the permission list is decoration: every administrator would
 * hold every capability implicitly, so a staff account created to run the leads
 * inbox could also change the company's bank details. The owner holds
 * everything; everybody else holds exactly what has been ticked for them.
 *
 * Existing administrators are marked as owners, because they already had
 * unrestricted access and a migration is not the place to take it away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false)->after('role');
        });

        DB::table('users')->where('role', 'admin')->update(['is_owner' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_owner');
        });
    }
};

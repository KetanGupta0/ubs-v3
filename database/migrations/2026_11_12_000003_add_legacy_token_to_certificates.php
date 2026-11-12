<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token a v2 certificate's QR code carries.
 *
 * Every certificate printed by the old site links to /verify/<token>, a 64
 * character mixed-case string. v3 issues its own codes and upper-cases what
 * it is given, so without this the QR code on every certificate already in
 * somebody's hands would stop working on cutover day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('legacy_token', 128)->nullable()->unique()->after('verification_code');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropUnique(['legacy_token']);
            $table->dropColumn('legacy_token');
        });
    }
};

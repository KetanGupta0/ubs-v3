<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device details on Sanctum tokens.
 *
 * The mobile apps in Phase 8 need one token per device so a single handset can
 * be signed out without ending every other session, and a push token has to
 * live somewhere that dies with the device's access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('platform', 20)->nullable()->after('abilities');
            $table->string('device_identifier')->nullable()->after('platform');
            $table->string('app_version', 20)->nullable()->after('device_identifier');
            $table->text('push_token')->nullable()->after('app_version');

            $table->index('device_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['device_identifier']);
            $table->dropColumn(['platform', 'device_identifier', 'app_version', 'push_token']);
        });
    }
};

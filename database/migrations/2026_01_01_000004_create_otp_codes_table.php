<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One time codes for sign in, mobile verification and step up checks.
 *
 * Codes are stored hashed. A leaked database backup should not hand someone a
 * working sign in code for every account, and nothing in the system ever needs
 * to read a code back, only compare one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            // Stored alongside user_id so a code can be looked up by what the
            // person typed, before we have decided who they are.
            $table->string('destination');
            $table->string('channel', 10);
            $table->string('purpose', 40);

            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('request_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['destination', 'purpose', 'consumed_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};

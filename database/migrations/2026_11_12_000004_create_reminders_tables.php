<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Reminders to students (EXPANSION_PLAN §6): before a class, before work
 * closes, after two missed classes. Each kind can be switched off by the
 * student, and each is sent once per person per thing, which `reminders_sent`
 * remembers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Only the kinds somebody switched off are stored; absent is on.
            $table->json('reminder_preferences')->nullable()->after('timezone');
        });

        Schema::create('reminders_sent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('sent_at');

            $table->unique(['user_id', 'kind', 'subject_type', 'subject_id'], 'reminders_sent_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders_sent');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reminder_preferences');
        });
    }
};

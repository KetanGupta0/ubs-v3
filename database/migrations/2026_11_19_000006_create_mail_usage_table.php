<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much mail went out each day, against the limit the hosting plan sets
 * (X15). One row a day, counted by recipient as the mail server counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_usage', function (Blueprint $table) {
            $table->date('day')->primary();
            // Every recipient of every message the mailer handed over.
            $table->unsignedInteger('sent')->default(0);
            // Of those, greetings and announcements (§8).
            $table->unsignedInteger('promotional')->default(0);
            // Messages the mail server would not take, after their retries.
            $table->unsignedInteger('refused')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_usage');
    }
};

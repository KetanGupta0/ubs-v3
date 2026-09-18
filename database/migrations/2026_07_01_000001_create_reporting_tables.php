<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved views and scheduled reports.
 *
 * Both store a filter set rather than a result. A saved view that held rows
 * would be a stale copy of a list; holding the question instead means the
 * answer is always current, and it is the same question the URL already
 * carries, so a view and a shared link are the same thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The screen this belongs to, as its path: '/admin/invoices'. A
             * view is only offered on the list it was saved from, because a
             * filter set means nothing anywhere else.
             */
            $table->string('screen', 120);
            $table->string('name', 120);

            // The whole query: search, sort, filters, page size.
            $table->json('state');

            /*
             * A shared view is visible to every administrator. Deliberately
             * not editable by them: the person who saved it owns it, so
             * somebody else cannot quietly change what a colleague's bookmark
             * means.
             */
            $table->boolean('is_shared')->default(false);
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'screen']);
            $table->index(['screen', 'is_shared']);
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('report', 60);
            $table->string('name', 120)->nullable();

            // 'daily', 'weekly' or 'monthly', and which day it lands on.
            $table->string('cadence', 20)->default('monthly');
            $table->unsignedTinyInteger('day')->nullable();
            $table->unsignedTinyInteger('hour')->default(7);

            $table->json('filters')->nullable();

            /*
             * Who it goes to. Addresses rather than user ids, because the
             * usual recipient is an accountant or a college coordinator who
             * has no account here.
             */
            $table->json('recipients');
            $table->string('format', 10)->default('pdf');

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'cadence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('saved_views');
    }
};

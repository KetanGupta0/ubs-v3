<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A notice for one student, alongside the ones for a batch, a course or
 * everybody. The old platform had these; v2 called them notices to a
 * student, and they come across in the migration as rows with user_id set
 * and audience 'student'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('batch_id')->constrained()->cascadeOnDelete();
            $table->index(['user_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'published_at']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

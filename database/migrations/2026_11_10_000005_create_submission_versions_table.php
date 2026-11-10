<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every earlier version of a submission, as it stood when it was replaced.
 *
 * A resubmission used to overwrite the row, so the work a student first
 * handed in, and the feedback it was given, were gone. Now the row keeps the
 * latest version and this table keeps the rest, feedback and marks included.
 * v2's submission history (submission_number, is_latest) comes across here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1)->after('user_id');
        });

        Schema::create('submission_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->text('notes')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('demo_url')->nullable();
            $table->json('files')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_late')->default(false);
            $table->string('status', 20);
            $table->unsignedSmallInteger('marks')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['submission_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_versions');

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};

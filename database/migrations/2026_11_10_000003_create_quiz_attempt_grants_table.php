<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Extra attempts at a quiz for one student: a power cut mid attempt, a
 * medical absence, a resit agreed after a review. One row per grant, so the
 * history of who allowed what, and why, survives; the allowance is the
 * quiz's own number plus every grant for that student. v2's
 * quiz_attempt_overrides come across as rows here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempt_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('extra_attempts')->default(1);
            $table->text('reason');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['quiz_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_grants');
    }
};

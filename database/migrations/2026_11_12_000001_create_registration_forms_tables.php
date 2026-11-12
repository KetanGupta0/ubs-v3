<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Registration forms anybody can fill without an account (EXPANSION_PLAN §5).
 *
 * A form belongs to one course, and optionally one batch of it. What it asks
 * for beyond name, email and mobile is declared on the form, and each answer
 * set is one row in `registrations`, so a form's submissions are a list like
 * every other list, exportable as it stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_forms', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title', 160);
            $table->text('intro')->nullable();

            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->unsignedInteger('capacity')->nullable();

            // 'course': the course fee is raised as usual. 'free': waived for
            // everybody who comes in through this form.
            $table->string('fee', 10)->default('course');
            // 'auto': a confirmed registration enrols at once. 'review': it
            // waits for somebody to approve it.
            $table->string('approval', 10)->default('review');

            $table->json('fields')->nullable();
            $table->json('questions')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name', 120);
            $table->string('email');
            $table->string('mobile', 20)->nullable();
            $table->json('details')->nullable();
            $table->json('answers')->nullable();

            $table->string('status', 24)->default('received');
            // Whether this registration is what made the account, which
            // decides whether the holder still has to confirm it.
            $table->boolean('account_created')->default(false);
            $table->string('note', 500)->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            // One registration per address per form. A second submission is
            // not a second place, and it is not an error the sender sees.
            $table->unique(['registration_form_id', 'email']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('registration_forms');
    }
};

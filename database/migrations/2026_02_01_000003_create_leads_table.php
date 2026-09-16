<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries from the public site.
 *
 * Every form on the site writes here, carrying what the visitor was looking at
 * when they asked. Without that context an enquiry is just a name and a
 * message, and whoever answers it has to start the conversation from nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();

            $table->string('name');
            $table->string('email');
            $table->string('mobile', 20)->nullable();
            $table->string('company')->nullable();
            $table->text('message')->nullable();

            // What they were looking at.
            $table->string('interest', 30)->default('general');
            $table->foreignId('solution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_page')->nullable();
            $table->string('referrer')->nullable();

            $table->string('budget_band', 40)->nullable();
            $table->string('timeline', 40)->nullable();

            $table->string('status', 20)->default('new');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_notes')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['interest', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};

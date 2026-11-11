<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people shown on the public site: the team on the About page and the
 * mentors on the Training page. One person can be both.
 *
 * Empty on a fresh install on purpose. These are real people or nobody: a
 * section of invented faces is fabricated proof, so each section renders
 * nothing until somebody adds a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('role', 150);
            $table->text('bio')->nullable();
            $table->string('experience', 60)->nullable();
            $table->json('skills')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('linkedin_url', 300)->nullable();
            $table->boolean('on_about')->default(true);
            $table->boolean('is_mentor')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};

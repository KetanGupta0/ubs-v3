<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courses and batches.
 *
 * Created here because the public training pages need them. Phase 5 extends
 * these with modules, lessons, attendance and everything else the learning
 * management system requires, rather than creating a second set of tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('type', 20)->default('course');
            $table->string('tagline');
            $table->text('summary');
            $table->longText('description')->nullable();

            $table->string('level', 20)->default('beginner');
            $table->unsignedSmallInteger('duration_weeks')->nullable();
            $table->unsignedSmallInteger('hours_per_week')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->unsignedInteger('sale_price')->nullable();

            /*
             * Whether this appears on the public site at all. A programme can be
             * run entirely inside the learning management system, for a cohort
             * that was enrolled some other way.
             */
            $table->string('visibility', 20)->default('public');

            $table->json('syllabus')->nullable();
            $table->json('outcomes')->nullable();
            $table->json('prerequisites')->nullable();
            $table->json('tools')->nullable();
            $table->json('audience')->nullable();

            $table->string('accent', 20)->default('brand');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('seo')->nullable();

            $table->timestamps();

            $table->index(['visibility', 'is_published']);
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->string('code', 40)->unique();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // For example [{"day":"Mon","from":"19:00","to":"20:30"}].
            $table->json('schedule')->nullable();
            $table->string('timezone', 64)->default('Asia/Kolkata');

            $table->unsignedSmallInteger('capacity')->nullable();
            $table->unsignedSmallInteger('seats_taken')->default(0);

            $table->string('status', 20)->default('upcoming');
            $table->boolean('is_published')->default(true);

            $table->timestamps();

            $table->index(['status', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
        Schema::dropIfExists('courses');
    }
};

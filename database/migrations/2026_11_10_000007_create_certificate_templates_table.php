<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How a course's certificates look: a background, the wording, who signs.
 *
 * One fixed layout with these parts swapped in, rather than a free canvas,
 * so a certificate cannot be designed into something unreadable or into one
 * that hides its verification code. A template with no course is the
 * default for every course without its own.
 *
 * The certificate records which template it was issued with, and its PDF is
 * cached when issued, so changing a template never changes a certificate
 * somebody already holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('heading', 80)->default('Certificate of completion');
            $table->string('body_line', 120)->default('has completed');
            $table->string('signatory_name', 120)->nullable();
            $table->string('signatory_title', 120)->nullable();
            $table->string('signature_path')->nullable();
            $table->string('background_path')->nullable();
            $table->string('accent', 7)->default('#4f46e5');
            $table->boolean('show_grade')->default(true);
            $table->boolean('show_qr')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['course_id', 'is_active']);
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->foreignId('certificate_template_id')->nullable()->after('batch_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('certificates', fn (Blueprint $table) => $table->dropConstrainedForeignId('certificate_template_id'));
        Schema::dropIfExists('certificate_templates');
    }
};

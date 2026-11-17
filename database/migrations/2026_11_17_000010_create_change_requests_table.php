<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change requests (CL12).
 *
 * The client asks for something outside the scope; we estimate it and send
 * it as a quotation addendum, frozen as a PDF with its hash like every sent
 * document; the client approves it by signing. Approval changes the
 * project's value and timeline and adds the work as a milestone, and the
 * request stays as the record of why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('previous_id')->nullable()->constrained('change_requests')->nullOnDelete();

            $table->string('title', 160);
            $table->text('description');
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();

            // requested, quoted, approved, declined or withdrawn.
            $table->string('status', 16)->default('requested');

            // Our estimate, set when it is quoted. Paise, before tax.
            $table->unsignedBigInteger('amount')->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->text('estimate_note')->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->foreignId('quoted_by')->nullable()->constrained('users')->nullOnDelete();

            // Frozen as it goes out, and signed (CL2, CL3).
            $table->json('parties')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->foreignId('viewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decline_reason')->nullable();
            $table->foreignId('milestone_id')->nullable()->constrained('project_milestones')->nullOnDelete();

            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};

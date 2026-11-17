<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Waiting on you" items (CL10): what we need from a client to carry on,
 * such as brand assets, content, access to a system or a decision, each
 * with a due date. Every step (asked, answered, asked again, closed) is an
 * event, which is what the time a project spent waiting is worked out from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiting_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // The organisation account, copied from the project for scoping.
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();

            $table->string('kind', 20);
            $table->string('title', 160);
            $table->text('details')->nullable();
            $table->date('due_on')->nullable();
            $table->boolean('blocks_work')->default(true);

            // open, answered, closed or cancelled.
            $table->string('status', 16)->default('open');
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('waiting_item_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiting_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // raised, answered, reopened, closed or cancelled.
            $table->string('action', 16);
            $table->text('body')->nullable();
            $table->json('document_ids')->nullable();
            $table->timestamps();

            $table->index(['waiting_item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiting_item_events');
        Schema::dropIfExists('waiting_items');
    }
};

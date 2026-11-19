<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant on the public site (§9.2): a visitor's conversation with it,
 * kept for the period set in the panel and then deleted, and the questions it
 * could not answer, which show where the site's own words fall short.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            // The visitor's browser holds the token; only its hash is kept.
            $table->char('token_hash', 64)->unique();
            $table->string('page', 255)->nullable();
            $table->unsignedSmallInteger('visitor_messages')->default(0);
            // lead, capped, refused, or null while it is just a conversation.
            $table->string('outcome', 10)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            // Details the assistant offered to pass on, waiting for the visitor to press Send.
            $table->json('lead_offer')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_conversation_id')->constrained()->cascadeOnDelete();
            // visitor or assistant.
            $table->string('role', 10);
            $table->text('body');
            // The tools the assistant used for this reply, so a person can see where an answer came from.
            $table->json('tools')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('assistant_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistant_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('question', 300);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_gaps');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};

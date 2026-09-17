<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations, messages and who has seen what.
 *
 * Two kinds of room, and they are different enough to be worth naming: a
 * client's own thread with us, optionally about one project, and a batch group
 * where a whole cohort talks at once.
 *
 * `kind` is an enum rather than a string, so the text, image and audio rule is
 * enforced by the database and not only by the controller that happens to be
 * writing today. That rule was asked for explicitly, and a future code path
 * that wants to attach a PDF should fail loudly rather than quietly succeed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            $table->enum('type', ['client_direct', 'batch_group']);

            // Whichever of these applies. A client thread carries the client
            // and optionally a project; a batch group carries the batch.
            $table->foreignId('client_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title')->nullable();

            /*
             * Denormalised so a list of threads can be ordered and previewed
             * without touching the messages table once per row. Written by the
             * same service that writes the message.
             */
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 160)->nullable();

            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->index(['type', 'last_message_at']);
            $table->index(['client_id', 'last_message_at']);
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What this person is in this room: 'member' or 'staff'.
            $table->string('role', 20)->default('member');

            $table->timestamp('joined_at');

            /*
             * The unread count is derived from this rather than counted from
             * receipts: one column read per row beats one query per message,
             * and "everything after the last time I looked" is what a person
             * means by unread anyway.
             */
            $table->timestamp('last_read_at')->nullable();
            $table->boolean('is_muted')->default(false);
            $table->timestamp('left_at')->nullable();

            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'last_read_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();

            $table->enum('kind', ['text', 'image', 'audio']);

            // Text for a text message, and the caption for the other two.
            $table->text('body')->nullable();

            $table->string('media_path')->nullable();
            $table->string('media_mime', 120)->nullable();
            $table->unsignedInteger('media_size')->default(0);

            /*
             * Dimensions for an image, duration and waveform peaks for audio.
             * The peaks are computed once on upload, because asking every
             * viewer's browser to decode the file to draw a waveform is a lot
             * of work to repeat.
             */
            $table->json('media_meta')->nullable();

            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->timestamp('sent_at');
            $table->timestamp('edited_at')->nullable();
            $table->softDeletes();

            $table->timestamps();

            $table->index(['conversation_id', 'sent_at']);
        });

        Schema::create('message_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_receipts');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Running a batch group (EXPANSION_PLAN §7): a pinned message, announcement
 * mode, keeping a student from posting for a while, and reports from members
 * that go to a queue. Moderation is done by people; nothing here reads a
 * message to decide anything.
 *
 * The owner becomes an admin of every batch group, existing ones included,
 * muted, so they can run every group without collecting an unread badge for
 * each one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('announcement_only')->default(false)->after('title');
            $table->foreignId('pinned_message_id')->nullable()->after('announcement_only')->constrained('messages')->nullOnDelete();
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            // Not the same as is_muted, which is this person muting the room's
            // notifications. This is the room keeping them from posting.
            $table->timestamp('silenced_until')->nullable()->after('is_muted');
        });

        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 500)->nullable();
            // open, dismissed (nothing needed doing), actioned (removed or silenced).
            $table->string('status', 12)->default('open');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'reporter_id']);
            $table->index(['status', 'created_at']);
        });

        // The owner into every batch group that already exists, read up to now.
        $owners = DB::table('users')->where('is_owner', true)->pluck('id');
        $groups = DB::table('conversations')->where('type', 'batch_group')->pluck('id');

        foreach ($groups as $conversationId) {
            foreach ($owners as $ownerId) {
                $exists = DB::table('conversation_participants')
                    ->where('conversation_id', $conversationId)
                    ->where('user_id', $ownerId)
                    ->first();

                if ($exists) {
                    DB::table('conversation_participants')->where('id', $exists->id)->update(['role' => 'admin', 'left_at' => null]);

                    continue;
                }

                DB::table('conversation_participants')->insert([
                    'conversation_id' => $conversationId,
                    'user_id' => $ownerId,
                    'role' => 'admin',
                    'joined_at' => now(),
                    'last_read_at' => now(),
                    'is_muted' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reports');

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn('silenced_until');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinned_message_id');
            $table->dropColumn('announcement_only');
        });
    }
};

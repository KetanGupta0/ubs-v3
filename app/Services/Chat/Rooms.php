<?php

namespace App\Services\Chat;

use App\Models\Batch;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Enrollment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Finding the room a conversation belongs in, and keeping it populated.
 *
 * Rooms are never created by a person pressing "new conversation". They follow
 * from something that already exists — a client, a project, a batch — because
 * a chat application where two people can create three threads about the same
 * project is a chat application where the answer is in the other one.
 */
class Rooms
{
    /**
     * The thread for one client, optionally about one project.
     *
     * A client with no projects has one general thread. A client with projects
     * gets one per project plus the general one, because "which of my four
     * projects is this about" is the first thing anybody asks.
     */
    public function forClient(User $client, ?Project $project = null): Conversation
    {
        $conversation = Conversation::query()
            ->where('type', 'client_direct')
            ->where('client_id', $client->id)
            ->where('project_id', $project?->id)
            ->first();

        if (! $conversation) {
            $conversation = Conversation::query()->create([
                'type' => 'client_direct',
                'client_id' => $client->id,
                'project_id' => $project?->id,
                'title' => $project?->name,
            ]);
        }

        $this->join($conversation, $client, 'member');

        return $conversation;
    }

    /** The group for one batch. */
    public function forBatch(Batch $batch): Conversation
    {
        $conversation = Conversation::query()
            ->where('type', 'batch_group')
            ->where('batch_id', $batch->id)
            ->first();

        if (! $conversation) {
            $conversation = Conversation::query()->create([
                'type' => 'batch_group',
                'batch_id' => $batch->id,
                'title' => $batch->name,
            ]);
        }

        $this->syncBatch($conversation, $batch);

        return $conversation;
    }

    /**
     * Put the batch's current students in the room, and take out anybody who
     * has left it.
     *
     * Somebody who drops stops receiving messages but keeps what they already
     * read: `left_at` rather than a deleted row, so a conversation they were
     * part of does not silently rewrite itself.
     */
    public function syncBatch(Conversation $conversation, ?Batch $batch = null): void
    {
        $batch ??= $conversation->batch;

        if (! $batch) {
            return;
        }

        $studentIds = Enrollment::query()
            ->where('batch_id', $batch->id)
            ->active()
            ->pluck('user_id');

        foreach ($studentIds as $studentId) {
            $this->join($conversation, $studentId, 'member');
        }

        if ($batch->trainer_id) {
            $this->join($conversation, $batch->trainer_id, 'staff');
        }

        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'member')
            ->whereNotIn('user_id', $studentIds)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);
    }

    /** Add somebody to a room, or bring them back if they had left. */
    public function join(Conversation $conversation, User|int $user, string $role = 'member'): ConversationParticipant
    {
        $id = $user instanceof User ? $user->id : $user;

        $participant = ConversationParticipant::query()->firstOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $id],
            ['role' => $role, 'joined_at' => now()],
        );

        if ($participant->left_at) {
            $participant->forceFill(['left_at' => null])->save();
        }

        return $participant;
    }

    /**
     * Every room this person can see, newest conversation first.
     *
     * A client sees their own threads. A student sees their batches. An
     * administrator with the permission sees everything, because answering is
     * their job and a thread they cannot find is a thread nobody answers.
     */
    public function visibleTo(User $user): Collection
    {
        $query = Conversation::query()
            ->with(['client:id,name,avatar_path', 'project:id,name', 'batch:id,name,course_id', 'batch.course:id,title'])
            ->active()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($user->isAdmin() && $user->hasPermission('chat.reply')) {
            return $query->get();
        }

        return $query->forParticipant($user)->get();
    }

    /**
     * Make sure a client has the rooms they should have.
     *
     * Called when the chat screen is opened rather than when a project is
     * created, so a client who has been with us since before this phase does
     * not have to wait for their next project to get a thread.
     */
    public function ensureClientRooms(User $client): void
    {
        $this->forClient($client);

        Project::query()
            ->where('client_id', $client->id)
            ->get()
            ->each(fn (Project $project) => $this->forClient($client, $project));
    }

    /** The same, for a student and their batches. */
    public function ensureStudentRooms(User $student): void
    {
        Enrollment::query()
            ->where('user_id', $student->id)
            ->active()
            ->whereNotNull('batch_id')
            ->with('batch')
            ->get()
            ->each(function (Enrollment $enrolment) {
                if ($enrolment->batch) {
                    $this->forBatch($enrolment->batch);
                }
            });
    }
}

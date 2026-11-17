<?php

use App\Events\Chat\RoomUpdated;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\GroupSilenced;
use App\Notifications\MessageReported;
use App\Services\Chat\Messenger;
use App\Services\Chat\Rooms;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Notification::fake();

    $this->owner = User::factory()->owner()->create(['name' => 'The Owner']);
    $this->trainer = User::factory()->admin()->create(['name' => 'Meera Trainer']);
    // A trainer runs their own batch's group without holding chat.reply.
    $this->trainer->permissions()->attach(Permission::query()->where('key', 'students.view')->first());

    $this->course = Course::query()->create(['title' => 'Python basics', 'slug' => 'grp-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'x']);
    $this->batch = Batch::query()->create(['course_id' => $this->course->id, 'name' => 'Morning batch', 'code' => 'GM-'.uniqid(), 'trainer_id' => $this->trainer->id]);

    $this->enrol = function (string $name) {
        $student = User::factory()->student()->create(['name' => $name]);
        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id, 'enrolled_at' => now(), 'has_paid' => true]);

        return $student;
    };

    $this->asha = ($this->enrol)('Asha Verma');
    $this->ravi = ($this->enrol)('Ravi Kumar');

    $this->group = app(Rooms::class)->forBatch($this->batch);
    $this->say = fn (User $who, string $text) => app(Messenger::class)->sendText($this->group, $who, $text);
});

function groupToken(User $user): string
{
    return $user->createToken('phone', ['*'])->plainTextToken;
}

/* ---------------------------------------------------------- the owner */

it('makes the owner a muted admin of every batch group, with no unread badge', function () {
    $participant = $this->group->participantFor($this->owner);

    expect($participant->role)->toBe('admin')
        ->and($participant->is_muted)->toBeTrue();

    $this->travel(1)->minutes();
    ($this->say)($this->asha, 'Hello everybody');

    $this->actingAs($this->owner)->get('/chat')
        ->assertInertia(fn ($page) => $page
            ->where('conversations', fn ($rooms) => collect($rooms)->firstWhere('id', $this->group->id)['unread'] === 0
                && collect($rooms)->firstWhere('id', $this->group->id)['muted'] === true));

    // Unmuting brings the count back, for the owner's own choice.
    $this->actingAs($this->owner)->putJson("/chat/{$this->group->id}/mute", ['muted' => false])->assertOk();
    $this->actingAs($this->owner)->get('/chat')
        ->assertInertia(fn ($page) => $page->where('conversations', fn ($rooms) => collect($rooms)->firstWhere('id', $this->group->id)['unread'] === 1));
});

it('keeps the owner an admin when the group is put in step again, and when they reply', function () {
    ($this->say)($this->owner, 'Welcome, all.');
    app(Rooms::class)->syncBatch($this->group);

    expect($this->group->participantFor($this->owner)->role)->toBe('admin')
        ->and($this->group->participants()->where('user_id', $this->owner->id)->count())->toBe(1);
});

/* ---------------------------------------------------------- who runs it */

it('lets the owner, the batch trainer and chat staff run the group, and nobody else', function () {
    $chatStaff = User::factory()->admin()->create();
    $chatStaff->permissions()->attach(Permission::query()->where('key', 'chat.reply')->first());
    $otherStaff = User::factory()->admin()->create();

    expect($this->group->canBeModeratedBy($this->owner))->toBeTrue()
        ->and($this->group->canBeModeratedBy($this->trainer))->toBeTrue()
        ->and($this->group->canBeModeratedBy($chatStaff))->toBeTrue()
        ->and($this->group->canBeModeratedBy($otherStaff))->toBeFalse()
        ->and($this->group->canBeModeratedBy($this->asha))->toBeFalse();

    $this->actingAs($this->asha)->putJson("/chat/{$this->group->id}/announcement", ['on' => true])->assertForbidden();
    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/members/{$this->ravi->id}/silence", ['for' => '1h'])->assertForbidden();
    expect($this->group->fresh()->announcement_only)->toBeFalse();
});

/* -------------------------------------------------------------- pinning */

it('pins one message for everybody, on the record and over the socket', function () {
    Event::fake([RoomUpdated::class]);
    $message = ($this->say)($this->trainer, 'Assignment 2 is due on Friday.');

    $this->actingAs($this->trainer)->postJson("/chat/{$this->group->id}/pin", ['message_id' => $message->id])
        ->assertOk()
        ->assertJsonPath('room.pinned.id', $message->id);

    Event::assertDispatched(RoomUpdated::class, fn ($event) => $event->settings['pinned']['id'] === $message->id);
    expect(AuditLog::query()->where('action', 'chat.pinned')->exists())->toBeTrue();

    $this->actingAs($this->asha)->get("/chat/{$this->group->id}")
        ->assertInertia(fn ($page) => $page->where('conversation.pinned.preview', 'Assignment 2 is due on Friday.'));

    // The poll carries it too, for anybody without a socket.
    $this->actingAs($this->asha)->getJson("/chat/{$this->group->id}/messages?after=0")
        ->assertJsonPath('room.pinned.id', $message->id);

    // A removed message does not stay pinned.
    app(Messenger::class)->remove($message, $this->trainer);
    expect($this->group->fresh()->pinned_message_id)->toBeNull();
});

/* ----------------------------------------------------- announcement mode */

it('lets only the trainer and the team post in announcement mode, on the web and the phone', function () {
    $this->actingAs($this->trainer)->putJson("/chat/{$this->group->id}/announcement", ['on' => true])->assertOk()
        ->assertJsonPath('room.announcementOnly', true);

    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/messages", ['kind' => 'text', 'body' => 'Can I ask something?'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only the trainer and the team can post here at the moment. You can still read and report messages.');

    $this->withToken(groupToken($this->ravi))
        ->postJson("/api/v1/chat/{$this->group->id}/messages", ['kind' => 'text', 'body' => 'From my phone'])
        ->assertStatus(422);

    $this->actingAs($this->trainer)->postJson("/chat/{$this->group->id}/messages", ['kind' => 'text', 'body' => 'Class moved to 7.'])->assertOk();

    $this->actingAs($this->asha)->get("/chat/{$this->group->id}")
        ->assertInertia(fn ($page) => $page->where('conversation.announcementOnly', true)->where('conversation.blockedReason', fn ($reason) => str_contains($reason, 'Only the trainer')));

    $this->actingAs($this->trainer)->putJson("/chat/{$this->group->id}/announcement", ['on' => false]);
    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/messages", ['kind' => 'text', 'body' => 'Thanks!'])->assertOk();

    expect(AuditLog::query()->where('action', 'chat.announcement_only')->count())->toBe(2)
        ->and(Message::query()->where('body', 'Can I ask something?')->exists())->toBeFalse();
});

/* ------------------------------------------------- keeping somebody quiet */

it('keeps a student from posting for a while, tells them, and lifts it', function () {
    $this->actingAs($this->trainer)
        ->postJson("/chat/{$this->group->id}/members/{$this->asha->id}/silence", ['for' => '1d', 'reason' => 'Off topic, again.'])
        ->assertOk();

    Notification::assertSentTo($this->asha, GroupSilenced::class, fn ($n) => $n->reason === 'Off topic, again.');

    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/messages", ['kind' => 'text', 'body' => 'Hello?'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($text) => str_starts_with($text, 'You cannot post in this group until'));
    $this->actingAs($this->ravi)->postJson("/chat/{$this->group->id}/messages", ['kind' => 'text', 'body' => 'I can'])->assertOk();

    // Who is kept from posting is for the people who run the group.
    $this->actingAs($this->trainer)->get("/chat/{$this->group->id}")
        ->assertInertia(fn ($page) => $page->where('participants', fn ($people) => collect($people)->firstWhere('id', $this->asha->id)['silencedUntil'] !== null));
    $this->actingAs($this->ravi)->get("/chat/{$this->group->id}")
        ->assertInertia(fn ($page) => $page->where('participants', fn ($people) => collect($people)->firstWhere('id', $this->asha->id)['silencedUntil'] === null));

    $this->travel(25)->hours();
    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/messages", ['kind' => 'text', 'body' => 'Back again'])->assertOk();

    $this->actingAs($this->trainer)->postJson("/chat/{$this->group->id}/members/{$this->asha->id}/silence", ['for' => '7d']);
    $this->actingAs($this->trainer)->deleteJson("/chat/{$this->group->id}/members/{$this->asha->id}/silence")->assertOk();
    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/messages", ['kind' => 'text', 'body' => 'Free to talk'])->assertOk();

    expect(AuditLog::query()->where('action', 'chat.silenced')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'chat.silence_lifted')->count())->toBe(1);
});

it('will not keep the trainer or the owner from posting', function () {
    $this->actingAs($this->owner)->postJson("/chat/{$this->group->id}/members/{$this->trainer->id}/silence", ['for' => '1h'])->assertStatus(422);

    expect($this->group->participantFor($this->trainer)->silenced_until)->toBeNull();
});

/* -------------------------------------------------------------- reports */

it('takes a member\'s report once, tells the trainer and the owner, and takes nothing down', function () {
    $message = ($this->say)($this->ravi, 'Something that should not be here');

    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/messages/{$message->id}/report", ['reason' => 'Rude'])
        ->assertOk()->assertJsonPath('message', 'Reported. The people who run this group will look at it.');
    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/messages/{$message->id}/report", ['reason' => 'Again'])
        ->assertOk()->assertJsonPath('message', 'Reported. The people who run this group will look at it.');

    expect(MessageReport::query()->count())->toBe(1)
        ->and($message->fresh()->trashed())->toBeFalse();

    Notification::assertSentTo($this->trainer, MessageReported::class, fn ($n) => $n->toArray($this->trainer)['url'] === "/chat/{$this->group->id}");
    Notification::assertSentTo($this->owner, MessageReported::class, fn ($n) => str_starts_with($n->toArray($this->owner)['url'], '/admin/chat/reports'));

    // Your own message, or a room you are not in, cannot be reported.
    $mine = ($this->say)($this->asha, 'My own message');
    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/messages/{$mine->id}/report")->assertStatus(422);
});

it('deals with a report from the queue: remove, which is audited, or dismiss', function () {
    $bad = ($this->say)($this->ravi, 'Something that should not be here');
    $fine = ($this->say)($this->ravi, 'Something perfectly fine');
    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/messages/{$bad->id}/report");
    $this->actingAs($this->asha)->postJson("/chat/{$this->group->id}/messages/{$fine->id}/report");

    $this->actingAs($this->owner)->get('/admin/chat/reports?filter[status]=open')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/chat/Reports')->has('table.rows', 2)->where('waiting', 2));

    $badReport = MessageReport::query()->where('message_id', $bad->id)->sole();
    $fineReport = MessageReport::query()->where('message_id', $fine->id)->sole();

    $this->actingAs($this->owner)->post("/admin/chat/reports/{$badReport->id}/remove")->assertSessionHas('success');
    $this->actingAs($this->owner)->post("/admin/chat/reports/{$fineReport->id}/dismiss")->assertSessionHas('success');

    expect($bad->fresh()->trashed())->toBeTrue()
        ->and($badReport->fresh()->status)->toBe('actioned')
        ->and($fine->fresh()->trashed())->toBeFalse()
        ->and($fineReport->fresh()->status)->toBe('dismissed')
        ->and(AuditLog::query()->where('action', 'chat.message_removed')->where('actor_id', $this->owner->id)->exists())->toBeTrue();

    $this->actingAs($this->trainer)->get('/admin/chat/reports')->assertForbidden();
});

it('records removing somebody else\'s message, and not removing your own', function () {
    $theirs = ($this->say)($this->ravi, 'Spam spam spam');
    $mine = ($this->say)($this->asha, 'Oops, wrong group');

    $this->actingAs($this->trainer)->delete("/chat/{$this->group->id}/messages/{$theirs->id}")->assertRedirect();
    $this->actingAs($this->asha)->delete("/chat/{$this->group->id}/messages/{$mine->id}")->assertRedirect();

    expect(AuditLog::query()->where('action', 'chat.message_removed')->count())->toBe(1);

    // A member cannot remove somebody else's.
    $other = ($this->say)($this->ravi, 'Still here');
    $this->actingAs($this->asha)->delete("/chat/{$this->group->id}/messages/{$other->id}")->assertForbidden();
});

it('tells a thread with no socket which messages were removed since it last asked', function () {
    $first = ($this->say)($this->ravi, 'This will be removed');
    $kept = ($this->say)($this->ravi, 'This stays');

    $poll = $this->actingAs($this->asha)->getJson("/chat/{$this->group->id}/messages?after={$kept->id}")->assertOk();
    $now = $poll->json('now');
    expect($poll->json('removed'))->toBe([]);

    $this->travel(5)->seconds();
    app(Messenger::class)->remove($first, $this->trainer);

    $this->actingAs($this->asha)->getJson("/chat/{$this->group->id}/messages?after={$kept->id}&since=".urlencode($now))
        ->assertJsonPath('removed', [$first->id])
        ->assertJsonPath('messages', []);

    // Nonsense in `since` falls back to the last few minutes rather than failing.
    $this->actingAs($this->asha)->getJson("/chat/{$this->group->id}/messages?after={$kept->id}&since=yesterday-ish")->assertOk();
});

it('stops the room\'s preview quoting a removed last message', function () {
    ($this->say)($this->asha, 'Fine message');
    $bad = ($this->say)($this->ravi, 'Something that should not be here');
    expect($this->group->fresh()->last_message_preview)->toBe('Something that should not be here');

    app(Messenger::class)->remove($bad, $this->trainer);

    expect($this->group->fresh()->last_message_preview)->toBe('Message removed');
});

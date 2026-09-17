<?php

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\MessageReceipt;
use App\Models\Permission;
use App\Models\Project;
use App\Models\User;
use App\Services\Chat\Messenger;
use App\Services\Chat\Rooms;
use App\Support\Chat\MessagePayload;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->owner = User::factory()->owner()->create();
    $this->client = User::factory()->client()->create();
    $this->student = User::factory()->student()->create();

    $this->project = Project::query()->create([
        'client_id' => $this->client->id,
        'name' => 'Warehouse system',
        'status' => 'in_progress',
    ]);

    $this->course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'chat-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'September batch',
        'code' => 'CH-'.uniqid(),
        'starts_on' => today()->subWeek(),
    ]);

    Enrollment::query()->create([
        'user_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeek(),
        'has_paid' => true,
    ]);
});

function clientRoom(): Conversation
{
    return app(Rooms::class)->forClient(test()->client, test()->project);
}

function batchRoom(): Conversation
{
    return app(Rooms::class)->forBatch(test()->batch);
}

/* --------------------------------------------------------------- rooms */

it('gives a client one thread per project and one general one', function () {
    $this->actingAs($this->client)->get('/chat')->assertOk();

    $rooms = Conversation::query()->where('client_id', $this->client->id)->get();

    expect($rooms)->toHaveCount(2)
        ->and($rooms->whereNull('project_id'))->toHaveCount(1)
        ->and($rooms->where('project_id', $this->project->id))->toHaveCount(1);
});

it('does not make a second thread for the same project', function () {
    $first = clientRoom();
    $second = clientRoom();

    expect($second->id)->toBe($first->id)
        ->and(Conversation::query()->count())->toBe(1);
});

it('puts the batch students and the trainer in the group', function () {
    $this->batch->forceFill(['trainer_id' => $this->owner->id])->save();

    $room = batchRoom();

    expect($room->participants()->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->student->id, $this->owner->id])->sort()->values()->all());
});

it('takes somebody out of the group when they drop the batch', function () {
    $room = batchRoom();

    Enrollment::query()->where('user_id', $this->student->id)->update(['status' => 'dropped']);

    app(Rooms::class)->syncBatch($room->fresh(), $this->batch);

    $participant = $room->participants()->where('user_id', $this->student->id)->sole();

    // Marked as having left rather than deleted: what they already read stays
    // true, and a conversation they were part of does not rewrite itself.
    expect($participant->left_at)->not->toBeNull()
        ->and($room->fresh()->includes($this->student))->toBeFalse();
});

/* --------------------------------------------------------- who gets in */

it('does not show one client another client’s thread', function () {
    $room = clientRoom();
    $stranger = User::factory()->client()->create();

    $this->actingAs($stranger)->get("/chat/{$room->id}")->assertNotFound();
});

it('does not let a student into a batch group they are not on', function () {
    $room = batchRoom();
    $stranger = User::factory()->student()->create();

    $this->actingAs($stranger)->get("/chat/{$room->id}")->assertNotFound();
});

it('lets an administrator with the permission answer any thread', function () {
    $room = clientRoom();

    $this->actingAs($this->owner)->get("/chat/{$room->id}")->assertOk();
});

it('keeps an administrator without the permission out', function () {
    $room = clientRoom();

    $staff = User::factory()->create(['role' => Role::Admin]);
    $staff->permissions()->sync(Permission::query()->where('key', 'students.view')->pluck('id'));

    $this->actingAs($staff)->get("/chat/{$room->id}")->assertNotFound();
});

/* ------------------------------------------------------------ sending */

it('sends a message and previews it on the conversation', function () {
    $room = clientRoom();

    $this->actingAs($this->client)
        ->postJson("/chat/{$room->id}/messages", ['kind' => 'text', 'body' => 'Is Thursday still on?'])
        ->assertOk()
        ->assertJsonPath('message.body', 'Is Thursday still on?')
        ->assertJsonPath('message.kind', 'text');

    expect($room->fresh()->last_message_preview)->toBe('Is Thursday still on?')
        ->and($room->fresh()->last_message_at)->not->toBeNull();
});

it('refuses an empty message', function () {
    $room = clientRoom();

    $this->actingAs($this->client)
        ->postJson("/chat/{$room->id}/messages", ['kind' => 'text', 'body' => '   '])
        ->assertStatus(422);

    expect(Message::query()->count())->toBe(0);
});

it('will not send into a room this person cannot open', function () {
    $room = clientRoom();
    $stranger = User::factory()->client()->create();

    $this->actingAs($stranger)
        ->postJson("/chat/{$room->id}/messages", ['kind' => 'text', 'body' => 'Hello?'])
        ->assertNotFound();

    expect(Message::query()->count())->toBe(0);
});

it('only quotes a message from the same room', function () {
    $mine = clientRoom();
    $theirs = batchRoom();

    $elsewhere = app(Messenger::class)->sendText($theirs, $this->student, 'In another room');

    $this->actingAs($this->client)->postJson("/chat/{$mine->id}/messages", [
        'kind' => 'text',
        'body' => 'Quoting across rooms',
        'reply_to_id' => $elsewhere->id,
    ])->assertOk();

    expect(Message::query()->where('conversation_id', $mine->id)->sole()->reply_to_id)->toBeNull();
});

/* -------------------------------------------------------------- media */

it('stores a photo, and strips what the camera wrote into it', function () {
    Storage::fake('private');

    $room = clientRoom();

    $this->actingAs($this->client)->post("/chat/{$room->id}/messages", [
        'kind' => 'image',
        'file' => UploadedFile::fake()->image('site-photo.jpg', 2400, 1200),
    ])->assertRedirect();

    $message = Message::query()->sole();

    expect($message->kind)->toBe('image')
        ->and($message->media_path)->toStartWith("chat/{$room->id}/")
        // Re-encoded, so the longest edge is capped and the EXIF block is gone.
        ->and($message->media_meta['width'])->toBe(1600);

    Storage::disk('private')->assertExists($message->media_path);
});

it('refuses a file that is not a picture or a sound', function () {
    Storage::fake('private');

    $room = clientRoom();

    $this->actingAs($this->client)->post("/chat/{$room->id}/messages", [
        'kind' => 'image',
        'file' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
    ])->assertSessionHasErrors('body');

    expect(Message::query()->count())->toBe(0);
});

it('will not store a fourth kind of message', function () {
    $room = clientRoom();

    $this->actingAs($this->client)
        ->postJson("/chat/{$room->id}/messages", ['kind' => 'video', 'body' => 'Look at this'])
        ->assertStatus(422);
});

it('serves media only to somebody in the room', function () {
    Storage::fake('private');

    $room = clientRoom();

    $this->actingAs($this->client)->post("/chat/{$room->id}/messages", [
        'kind' => 'image',
        'file' => UploadedFile::fake()->image('plan.jpg', 400, 300),
    ]);

    $message = Message::query()->sole();
    $url = app(MessagePayload::class)::for($message)['mediaUrl'];

    $this->actingAs($this->client)->get($url)->assertOk();

    // A signature proves the link came from us, not that the holder belongs
    // in the room.
    $this->actingAs(User::factory()->client()->create())->get($url)->assertNotFound();
});

/* ----------------------------------------------------------- receipts */

it('opens a receipt for everybody else in the room', function () {
    $this->batch->forceFill(['trainer_id' => $this->owner->id])->save();

    $room = batchRoom();
    $classmate = User::factory()->student()->create();

    Enrollment::query()->create([
        'user_id' => $classmate->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now(),
        'has_paid' => true,
    ]);

    app(Rooms::class)->syncBatch($room, $this->batch);

    app(Messenger::class)->sendText($room, $this->student, 'Anybody else stuck on migrations?');

    $message = Message::query()->sole();

    // Everyone but the sender.
    expect($message->receipts()->count())->toBe(2)
        ->and($message->receipts()->where('user_id', $this->student->id)->exists())->toBeFalse();
});

it('marks messages read when the thread is opened, and not the reader’s own', function () {
    $room = clientRoom();

    app(Messenger::class)->sendText($room, $this->client, 'Anything on the scanner bug?');

    $this->actingAs($this->owner)->get("/chat/{$room->id}")->assertOk();

    $message = Message::query()->sole();

    expect(MessageReceipt::query()->where('message_id', $message->id)->whereNotNull('read_at')->count())
        ->toBe(1)
        // Reading is not joining. Staff become members of a room by answering
        // in it, so a quick look does not hand somebody an unread badge for
        // every client on the platform.
        ->and($room->fresh()->includes($this->owner))->toBeFalse();
});

it('makes a member of staff part of the room once they answer in it', function () {
    $room = clientRoom();

    $this->actingAs($this->owner)
        ->postJson("/chat/{$room->id}/messages", ['kind' => 'text', 'body' => 'Looking at it now.'])
        ->assertOk();

    expect($room->fresh()->includes($this->owner))->toBeTrue()
        ->and($room->fresh()->participantFor($this->owner)->role)->toBe('staff');
});

it('counts as unread only what arrived since somebody last looked', function () {
    $room = clientRoom();

    app(Messenger::class)->sendText($room, $this->owner, 'One');
    app(Messenger::class)->sendText($room, $this->owner, 'Two');

    $participant = $room->fresh()->participantFor($this->client);

    expect($participant->unreadCount())->toBe(2);

    $this->actingAs($this->client)->get("/chat/{$room->id}");

    expect($room->fresh()->participantFor($this->client)->unreadCount())->toBe(0);
});

/* ------------------------------------------------------------ removing */

it('lets somebody take back their own message, leaving the bubble behind', function () {
    $room = clientRoom();

    $message = app(Messenger::class)->sendText($room, $this->client, 'Sent to the wrong thread');

    $this->actingAs($this->client)
        ->delete("/chat/{$room->id}/messages/{$message->id}")
        ->assertRedirect();

    expect($message->fresh()->trashed())->toBeTrue()
        ->and(Message::withTrashed()->count())->toBe(1);
});

it('does not let somebody remove another person’s message', function () {
    $room = batchRoom();
    $classmate = User::factory()->student()->create();

    app(Rooms::class)->join($room, $classmate);

    $message = app(Messenger::class)->sendText($room, $this->student, 'Mine');

    $this->actingAs($classmate)
        ->delete("/chat/{$room->id}/messages/{$message->id}")
        ->assertForbidden();

    expect($message->fresh()->trashed())->toBeFalse();
});

it('lets an administrator remove something from a group room', function () {
    $room = batchRoom();

    $message = app(Messenger::class)->sendText($room, $this->student, 'Something out of place');

    $this->actingAs($this->owner)
        ->delete("/chat/{$room->id}/messages/{$message->id}")
        ->assertRedirect();

    expect($message->fresh()->trashed())->toBeTrue();
});

/* ---------------------------------------------------------- the poll */

it('answers the poll with everything after the id it already has', function () {
    $room = clientRoom();

    $first = app(Messenger::class)->sendText($room, $this->client, 'First');
    app(Messenger::class)->sendText($room, $this->owner, 'Second');

    $this->actingAs($this->client)
        ->getJson("/chat/{$room->id}/messages?after={$first->id}")
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.body', 'Second');
});

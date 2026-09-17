<?php

use App\Enums\Role;
use App\Events\Chat\MessageRead;
use App\Events\Chat\MessageRemoved;
use App\Events\Chat\MessageSent;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Project;
use App\Models\User;
use App\Services\Chat\Messenger;
use App\Services\Chat\Rooms;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    /*
     * The suite runs with the null broadcaster, which answers every channel
     * authorisation with a shrug. These tests are about who is let onto a
     * channel, so they need a broadcaster that actually asks.
     */
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb', [
        'driver' => 'reverb',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'app_id' => 'test-app',
        'options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
    ]);

    // Channels are registered against whichever broadcaster was current when
    // the file was read, so swapping the driver means reading it again. This
    // also means the file itself is what is under test, rather than a copy of
    // its rules written out here.
    require base_path('routes/channels.php');

    $this->seed(PermissionSeeder::class);

    $this->owner = User::factory()->owner()->create();
    $this->client = User::factory()->client()->create();
    $this->student = User::factory()->student()->create();

    $this->project = Project::query()->create([
        'client_id' => $this->client->id,
        'name' => 'Warehouse system',
        'status' => 'in_progress',
    ]);

    $this->room = app(Rooms::class)->forClient($this->client, $this->project);
});

/* ------------------------------------------------------- what is sent */

it('broadcasts a message on the conversation channel', function () {
    Event::fake([MessageSent::class]);

    app(Messenger::class)->sendText($this->room, $this->client, 'Is Thursday still on?');

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
        return $event->message->conversation_id === $this->room->id
            && $event->broadcastOn()->name === 'presence-conversation.'.$this->room->id
            && $event->broadcastAs() === 'message.sent';
    });
});

it('broadcasts a read and a removal too', function () {
    Event::fake([MessageRead::class, MessageRemoved::class]);

    $message = app(Messenger::class)->sendText($this->room, $this->client, 'Anything yet?');

    app(Messenger::class)->markRead($this->room, $this->owner);
    app(Messenger::class)->remove($message, $this->client);

    Event::assertDispatched(MessageRead::class);
    Event::assertDispatched(MessageRemoved::class);
});

/**
 * The one that matters operationally: the socket layer failing must cost a
 * message its liveness and never its existence.
 */
it('still stores the message when broadcasting throws', function () {
    Log::spy();

    Event::listen(MessageSent::class, function () {
        throw new RuntimeException('Reverb is not running.');
    });

    $message = app(Messenger::class)->sendText($this->room, $this->client, 'Sent into a dead socket');

    expect($message->exists)->toBeTrue()
        ->and($this->room->fresh()->last_message_preview)->toBe('Sent into a dead socket');

    Log::shouldHaveReceived('warning')->once();
});

/* ------------------------------------------------ who may join a channel */

function authorise(User $user, int $conversationId)
{
    return test()->actingAs($user)->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'presence-conversation.'.$conversationId,
    ]);
}

it('lets somebody in the room onto its channel', function () {
    authorise($this->client, $this->room->id)->assertOk();
});

it('keeps everybody else off it', function () {
    authorise(User::factory()->client()->create(), $this->room->id)->assertForbidden();
});

it('lets an administrator who may answer onto any channel', function () {
    authorise($this->owner, $this->room->id)->assertOk();
});

it('keeps an administrator without the permission off it', function () {
    $staff = User::factory()->create(['role' => Role::Admin]);
    $staff->permissions()->sync(Permission::query()->where('key', 'billing.view')->pluck('id'));

    authorise($staff, $this->room->id)->assertForbidden();
});

it('puts only a name and a face on the presence channel', function () {
    $body = authorise($this->client, $this->room->id)->assertOk()->getContent();

    // Whatever a presence channel hands back is shown to everybody else in the
    // room, so an email address must not be in it.
    expect($body)
        ->toContain($this->client->name)
        ->not->toContain($this->client->email);
});

it('does not broadcast a student into a batch they left', function () {
    $course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'realtime-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
    ]);

    $batch = Batch::query()->create([
        'course_id' => $course->id,
        'name' => 'September batch',
        'code' => 'RT-'.uniqid(),
        'starts_on' => today()->subWeek(),
    ]);

    $enrolment = Enrollment::query()->create([
        'user_id' => $this->student->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now(),
        'has_paid' => true,
    ]);

    $room = app(Rooms::class)->forBatch($batch);

    authorise($this->student, $room->id)->assertOk();

    $enrolment->forceFill(['status' => 'dropped'])->save();
    app(Rooms::class)->syncBatch($room->fresh(), $batch);

    authorise($this->student, $room->id)->assertForbidden();
});

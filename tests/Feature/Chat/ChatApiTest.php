<?php

use App\Models\Project;
use App\Models\User;
use App\Services\Chat\Messenger;
use App\Services\Chat\Rooms;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->client = User::factory()->client()->create();

    $this->project = Project::query()->create([
        'client_id' => $this->client->id,
        'name' => 'Warehouse system',
        'status' => 'in_progress',
    ]);

    $this->room = app(Rooms::class)->forClient($this->client, $this->project);
});

function chatToken(User $user): string
{
    return $user->createToken('phone', ['*'])->plainTextToken;
}

it('lists the rooms a phone may open', function () {
    app(Messenger::class)->sendText($this->room, $this->client, 'From the web');

    $this->withToken(chatToken($this->client))
        ->getJson('/api/v1/chat')
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->room->id)
        ->assertJsonPath('data.0.preview', 'From the web');
});

it('sends from a phone and the web sees the same message', function () {
    $this->withToken(chatToken($this->client))
        ->postJson("/api/v1/chat/{$this->room->id}/messages", [
            'kind' => 'text',
            'body' => 'Sent from the train',
        ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Sent from the train');

    $this->actingAs($this->client)
        ->getJson("/chat/{$this->room->id}/messages")
        ->assertJsonPath('messages.0.body', 'Sent from the train');
});

it('does not open somebody else’s room over the API', function () {
    $stranger = User::factory()->client()->create();

    $this->withToken(chatToken($stranger))
        ->getJson("/api/v1/chat/{$this->room->id}/messages")
        ->assertNotFound();
});

it('turns away a request with no token', function () {
    $this->getJson('/api/v1/chat')->assertUnauthorized();
});

it('will not open chat with a two factor challenge token', function () {
    $challenge = $this->client->createToken('challenge', ['two-factor'])->plainTextToken;

    $this->withToken($challenge)->getJson('/api/v1/chat')->assertForbidden();
});

it('refuses a fourth kind of message over the API too', function () {
    $this->withToken(chatToken($this->client))
        ->postJson("/api/v1/chat/{$this->room->id}/messages", ['kind' => 'video', 'body' => 'no'])
        ->assertStatus(422);
});

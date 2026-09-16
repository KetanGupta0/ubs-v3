<?php

use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    $this->client = User::factory()->client()->create();
});

function clientToken(User $user): string
{
    return $user->createToken('phone', ['*'])->plainTextToken;
}

it('answers the overview for a signed in client', function () {
    Project::query()->create([
        'client_id' => $this->client->id,
        'name' => 'Warehouse system',
        'status' => 'in_progress',
        'progress_percent' => 62,
    ]);

    $this->withToken(clientToken($this->client))
        ->getJson('/api/v1/client/overview')
        ->assertOk()
        ->assertJsonPath('totals.activeProjects', 1)
        ->assertJsonPath('projects.0.name', 'Warehouse system');
});

it('keeps a student out of the client API', function () {
    $student = User::factory()->student()->create();

    $this->withToken(clientToken($student))
        ->getJson('/api/v1/client/projects')
        ->assertForbidden();
});

it('turns away a request with no token', function () {
    $this->getJson('/api/v1/client/projects')->assertUnauthorized();
});

it('will not open the client API with a two factor challenge token', function () {
    // The half finished sign in that token represents must not reach anything.
    $challenge = $this->client->createToken('challenge', ['two-factor'])->plainTextToken;

    $this->withToken($challenge)->getJson('/api/v1/client/projects')->assertForbidden();
});

it('does not return another client’s project over the API', function () {
    $stranger = User::factory()->client()->create();

    $theirs = Project::query()->create([
        'client_id' => $stranger->id,
        'name' => 'Theirs',
        'status' => 'in_progress',
    ]);

    $this->withToken(clientToken($this->client))
        ->getJson("/api/v1/client/projects/{$theirs->id}")
        ->assertNotFound();
});

it('raises a ticket from the mobile application', function () {
    $this->withToken(clientToken($this->client))
        ->postJson('/api/v1/client/tickets', [
            'subject' => 'Despatch note is wrong',
            'body' => 'The batch number is missing.',
            'priority' => 'high',
        ])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'reference']]);

    expect($this->client->supportTickets()->count())->toBe(1);
});

it('hands the app a URL for a document rather than the bytes', function () {
    $this->client->documents()->create([
        'name' => 'Specification.pdf',
        'path' => 'clients/1/spec.pdf',
        'mime' => 'application/pdf',
        'size' => 4096,
    ]);

    $this->withToken(clientToken($this->client))
        ->getJson('/api/v1/client/documents')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Specification.pdf')
        ->assertJsonMissingPath('data.0.path');
});

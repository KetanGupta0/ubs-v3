<?php

use App\Models\Document;
use App\Models\MaintenanceContract;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Money;

beforeEach(function () {
    $this->client = User::factory()->client()->create();
    $this->stranger = User::factory()->client()->create();
});

function projectFor(User $client, array $attributes = []): Project
{
    return Project::query()->create(array_merge([
        'client_id' => $client->id,
        'name' => 'Warehouse system',
        'status' => 'in_progress',
        'progress_percent' => 40,
    ], $attributes));
}

/* ------------------------------------------------------------- ownership */

it('does not admit that somebody else’s project exists', function () {
    $theirs = projectFor($this->stranger);

    // 404, not 403. A 403 confirms the record is there, which is a fact worth
    // nothing to them and worth something to whoever is walking the ids.
    $this->actingAs($this->client)->get("/client/projects/{$theirs->id}")->assertNotFound();
});

it('keeps every client list to its own rows', function () {
    projectFor($this->client, ['name' => 'Mine']);
    projectFor($this->stranger, ['name' => 'Theirs']);

    $this->actingAs($this->client)->get('/client/projects')
        ->assertInertia(fn ($page) => $page->has('table.rows', 1)->where('table.rows.0.name', 'Mine'));
});

it('will not let a student in through the client door', function () {
    $student = User::factory()->student()->create();

    $this->actingAs($student)->get('/client/projects')->assertRedirect('/student');
});

/* ------------------------------------------------------------- proposals */

it('hides a draft proposal from the client it was written for', function () {
    Proposal::query()->create([
        'client_id' => $this->client->id,
        'title' => 'Not ready yet',
        'status' => 'draft',
    ]);

    $this->actingAs($this->client)->get('/client/proposals')
        ->assertInertia(fn ($page) => $page->has('proposals', 0));
});

it('records who accepted a proposal and when', function () {
    $proposal = Proposal::query()->create([
        'client_id' => $this->client->id,
        'title' => 'Driver application',
        'status' => 'sent',
        'sent_at' => now()->subDay(),
        'valid_until' => today()->addWeek(),
    ]);

    $this->actingAs($this->client)
        ->post("/client/proposals/{$proposal->id}/respond", ['response' => 'accepted', 'note' => 'Go ahead.'])
        ->assertRedirect();

    $proposal->refresh();

    expect($proposal->status)->toBe('accepted')
        ->and($proposal->responded_at)->not->toBeNull()
        ->and($proposal->responded_ip)->not->toBeNull()
        ->and($proposal->response_note)->toBe('Go ahead.');
});

it('refuses a proposal that has passed its validity date', function () {
    $proposal = Proposal::query()->create([
        'client_id' => $this->client->id,
        'title' => 'Too late',
        'status' => 'sent',
        'sent_at' => now()->subMonth(),
        'valid_until' => today()->subDay(),
    ]);

    $this->actingAs($this->client)
        ->post("/client/proposals/{$proposal->id}/respond", ['response' => 'accepted'])
        ->assertSessionHasErrors('response');

    expect($proposal->fresh()->status)->toBe('sent');
});

it('will not let the same proposal be answered twice', function () {
    $proposal = Proposal::query()->create([
        'client_id' => $this->client->id,
        'title' => 'Already answered',
        'status' => 'rejected',
        'sent_at' => now()->subDay(),
        'responded_at' => now()->subHour(),
    ]);

    $this->actingAs($this->client)
        ->post("/client/proposals/{$proposal->id}/respond", ['response' => 'accepted'])
        ->assertSessionHasErrors('response');

    expect($proposal->fresh()->status)->toBe('rejected');
});

/* ------------------------------------------------------------- documents */

it('never serves a document belonging to somebody else', function () {
    $theirs = Document::query()->create([
        'client_id' => $this->stranger->id,
        'name' => 'Their contract.pdf',
        'path' => 'clients/999/secret.pdf',
        'mime' => 'application/pdf',
        'size' => 1024,
    ]);

    $this->actingAs($this->client)->get("/client/documents/{$theirs->id}/download")->assertNotFound();
});

it('keeps an internal document out of the client list', function () {
    Document::query()->create([
        'client_id' => $this->client->id,
        'name' => 'Internal estimate.xlsx',
        'path' => 'clients/1/internal.xlsx',
        'size' => 2048,
        'visible_to_client' => false,
    ]);

    $this->actingAs($this->client)->get('/client/documents')
        ->assertInertia(fn ($page) => $page->has('documents', 0));
});

/* --------------------------------------------------------------- support */

it('freezes the SLA clock onto the ticket when it is raised', function () {
    $contract = MaintenanceContract::query()->create([
        'client_id' => $this->client->id,
        'plan' => 'Standard',
        'starts_on' => today()->subMonth(),
        'ends_on' => today()->addMonths(11),
        'response_hours' => 8,
        'resolution_hours' => 48,
        'amount' => Money::toPaise(96000),
    ]);

    $this->actingAs($this->client)->post('/client/tickets', [
        'subject' => 'Despatch note is wrong',
        'body' => 'The batch number is missing on the print.',
        'priority' => 'high',
    ])->assertRedirect();

    $ticket = SupportTicket::query()->sole();

    expect($ticket->contract_id)->toBe($contract->id)
        ->and($ticket->created_at->diffInHours($ticket->response_due_at))->toEqualWithDelta(8, 1)
        ->and($ticket->created_at->diffInHours($ticket->resolution_due_at))->toEqualWithDelta(48, 1);

    // Changing the contract afterwards must not rewrite the promise that was made.
    $contract->forceFill(['response_hours' => 72])->save();

    expect($ticket->fresh()->created_at->diffInHours($ticket->response_due_at))->toEqualWithDelta(8, 1);
});

it('will not attach a ticket to another client’s contract', function () {
    $theirs = MaintenanceContract::query()->create([
        'client_id' => $this->stranger->id,
        'plan' => 'Theirs',
        'starts_on' => today()->subMonth(),
        'ends_on' => today()->addYear(),
        'amount' => 0,
    ]);

    $this->actingAs($this->client)->post('/client/tickets', [
        'subject' => 'Trying it on',
        'body' => 'Pointing at a contract that is not mine.',
        'priority' => 'low',
        'contract_id' => $theirs->id,
    ]);

    expect(SupportTicket::query()->sole()->contract_id)->toBeNull();
});

it('counts an unanswered ticket as breached the moment it is late', function () {
    $ticket = SupportTicket::query()->create([
        'client_id' => $this->client->id,
        'subject' => 'Late',
        'body' => 'Nobody has answered.',
        'response_due_at' => now()->subHour(),
    ]);

    // Not once somebody finally replies: it is late now.
    expect($ticket->breachedResponse())->toBeTrue();
});

/* -------------------------------------------------------------- payments */

it('shows a client only their own payment requests', function () {
    $mine = PaymentRequest::query()->create([
        'user_id' => $this->client->id,
        'title' => 'Milestone two',
    ]);
    $mine->price(Money::toPaise(100000), 18)->save();

    $theirs = PaymentRequest::query()->create([
        'user_id' => $this->stranger->id,
        'title' => 'Not mine',
    ]);
    $theirs->price(Money::toPaise(5000), 18)->save();

    $this->actingAs($this->client)->get('/client/payments')
        ->assertInertia(fn ($page) => $page->has('pending', 1)->where('pending.0.title', 'Milestone two'));

    $this->actingAs($this->client)->get("/client/payments/{$theirs->id}")->assertNotFound();
});

/* ------------------------------------------------------------- rendering */

it('renders every client screen, with rows in each', function () {
    $project = projectFor($this->client);

    $project->milestones()->create(['title' => 'Design sign off', 'due_date' => today()->addWeek()]);
    $project->updates()->create(['body' => 'Progress note.', 'visible_to_client' => true]);

    Proposal::query()->create([
        'client_id' => $this->client->id,
        'title' => 'Phase two',
        'status' => 'sent',
        'sent_at' => now()->subDay(),
        'valid_until' => today()->addWeek(),
    ]);

    Document::query()->create([
        'client_id' => $this->client->id,
        'project_id' => $project->id,
        'name' => 'Specification.pdf',
        'path' => 'clients/1/spec.pdf',
        'mime' => 'application/pdf',
        'size' => 4096,
    ]);

    $contract = MaintenanceContract::query()->create([
        'client_id' => $this->client->id,
        'project_id' => $project->id,
        'plan' => 'Standard',
        'starts_on' => today()->subMonth(),
        'ends_on' => today()->addYear(),
        'amount' => Money::toPaise(96000),
    ]);

    $ticket = SupportTicket::query()->create([
        'client_id' => $this->client->id,
        'contract_id' => $contract->id,
        'subject' => 'Something broke',
        'body' => 'It is broken.',
    ]);

    $payment = PaymentRequest::query()->create([
        'user_id' => $this->client->id,
        'title' => 'Milestone two',
    ]);
    $payment->price(Money::toPaise(100000), 18)->save();

    Subscription::query()->create([
        'client_id' => $this->client->id,
        'name' => 'Managed hosting',
        'amount' => Money::toPaise(18000),
        'interval' => 'quarterly',
        'starts_on' => today()->subMonths(3),
        'renews_on' => today()->addDays(20),
    ]);

    $paths = [
        '/client', '/client/reports',
        '/client/projects', "/client/projects/{$project->id}",
        '/client/proposals', '/client/documents',
        '/client/support', '/client/tickets/new', "/client/tickets/{$ticket->id}",
        '/client/payments', "/client/payments/{$payment->id}",
        '/client/transactions', '/client/subscriptions', '/client/api-keys',
    ];

    foreach ($paths as $path) {
        $this->actingAs($this->client)->get($path)->assertOk();
    }
});

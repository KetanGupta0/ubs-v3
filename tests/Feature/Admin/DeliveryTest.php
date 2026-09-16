<?php

use App\Models\ApiKey;
use App\Models\ApiKeyPlan;
use App\Models\Document;
use App\Models\MaintenanceContract;
use App\Models\PaymentRequest;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\ProjectUpdated;
use App\Notifications\ProposalSent;
use App\Services\Billing\Invoicer;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->client = User::factory()->client()->create();
});

function adminProject(User $client, array $attributes = []): Project
{
    return Project::query()->create(array_merge([
        'client_id' => $client->id,
        'name' => 'Warehouse system',
        'status' => 'in_progress',
        'progress_percent' => 30,
    ], $attributes));
}

/* -------------------------------------------------------------- projects */

it('gives every project a reference somebody can read out on a call', function () {
    expect(adminProject($this->client)->code)->toStartWith('UBS-P-');
});

it('refuses a target date that is before the start date', function () {
    $this->actingAs($this->owner)->post('/admin/projects', [
        'client_id' => $this->client->id,
        'name' => 'Backwards',
        'status' => 'planning',
        'progress_percent' => 0,
        'start_date' => '2026-06-01',
        'target_date' => '2026-01-01',
    ])->assertSessionHasErrors('target_date');
});

it('keeps an internal note off the client’s timeline', function () {
    $project = adminProject($this->client);

    $this->actingAs($this->owner)->post("/admin/projects/{$project->id}/updates", [
        'body' => 'Their API credentials expired again.',
        'visible_to_client' => false,
    ])->assertRedirect();

    $this->actingAs($this->client)->get("/client/projects/{$project->id}")
        ->assertInertia(fn ($page) => $page->has('updates', 0));
});

it('will not email an internal note to the client, whatever the box says', function () {
    Notification::fake();

    $project = adminProject($this->client);

    $this->actingAs($this->owner)->post("/admin/projects/{$project->id}/updates", [
        'body' => 'Internal only.',
        'visible_to_client' => false,
        'notify' => true,
    ]);

    Notification::assertNothingSent();
});

it('emails a visible update when asked to', function () {
    Notification::fake();

    $project = adminProject($this->client);

    $this->actingAs($this->owner)->post("/admin/projects/{$project->id}/updates", [
        'body' => 'Picking screens are on staging.',
        'visible_to_client' => true,
        'notify' => true,
    ]);

    Notification::assertSentTo($this->client, ProjectUpdated::class);
});

/* ------------------------------------------------------------- proposals */

it('will not send a proposal with nothing priced on it', function () {
    Notification::fake();

    $this->actingAs($this->owner)->post('/admin/proposals', [
        'client_id' => $this->client->id,
        'title' => 'Empty',
    ])->assertRedirect();

    $proposal = Proposal::query()->sole();

    $this->actingAs($this->owner)->post("/admin/proposals/{$proposal->id}/send")
        ->assertSessionHasErrors('send');

    expect($proposal->fresh()->status)->toBe('draft');
    Notification::assertNothingSent();
});

it('locks the price once the proposal has been sent', function () {
    Notification::fake();

    $proposal = Proposal::query()->create([
        'client_id' => $this->client->id,
        'title' => 'Driver application',
        'status' => 'draft',
        'created_by' => $this->owner->id,
    ]);

    $quotation = $proposal->quotation()->create(['client_id' => $this->client->id]);
    $quotation->items()->create([
        'description' => 'Build',
        'quantity' => 1,
        'unit_price' => Money::toPaise(500000),
        'tax_rate' => 18,
    ]);
    $quotation->recalculate();

    $this->actingAs($this->owner)->post("/admin/proposals/{$proposal->id}/send")->assertRedirect();

    // Editing a price after somebody has read it is how a disagreement starts.
    $this->actingAs($this->owner)->post("/admin/proposals/{$proposal->id}/items", [
        'description' => 'Sneaky extra',
        'quantity' => 1,
        'unit_price' => 100000,
        'tax_rate' => 18,
    ])->assertStatus(422);

    Notification::assertSentTo($this->client, ProposalSent::class);
});

it('keeps the old version when a proposal is revised', function () {
    $proposal = Proposal::query()->create([
        'client_id' => $this->client->id,
        'title' => 'First go',
        'status' => 'rejected',
        'sent_at' => now()->subWeek(),
        'responded_at' => now()->subDays(2),
        'response_note' => 'Too expensive.',
    ]);

    $this->actingAs($this->owner)->post("/admin/proposals/{$proposal->id}/revise")->assertRedirect();

    $revision = Proposal::query()->latest('id')->first();

    expect(Proposal::query()->count())->toBe(2)
        ->and($revision->version)->toBe(2)
        ->and($revision->status)->toBe('draft')
        ->and($proposal->fresh()->status)->toBe('rejected')
        ->and($proposal->fresh()->response_note)->toBe('Too expensive.');
});

it('will not delete a proposal that has already been sent', function () {
    $proposal = Proposal::query()->create([
        'client_id' => $this->client->id,
        'title' => 'On the record',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->actingAs($this->owner)->delete("/admin/proposals/{$proposal->id}")->assertStatus(422);

    expect(Proposal::query()->count())->toBe(1);
});

/* ------------------------------------------------------------- documents */

it('keeps the previous file when a document is replaced', function () {
    Storage::fake('private');

    $project = adminProject($this->client);

    $this->actingAs($this->owner)->post('/admin/documents', [
        'file' => UploadedFile::fake()->create('spec.pdf', 40, 'application/pdf'),
        'client_id' => $this->client->id,
        'project_id' => $project->id,
        'name' => 'Specification',
    ])->assertRedirect();

    $first = Document::query()->sole();

    $this->actingAs($this->owner)->post('/admin/documents', [
        'file' => UploadedFile::fake()->create('spec-v2.pdf', 45, 'application/pdf'),
        'client_id' => $this->client->id,
        'supersedes_id' => $first->id,
        'name' => 'Specification',
    ])->assertRedirect();

    $second = Document::query()->latest('id')->first();

    expect(Document::query()->count())->toBe(2)
        ->and($second->version)->toBe(2)
        ->and($first->fresh()->is_current)->toBeFalse()
        ->and(Storage::disk('private')->exists($first->path))->toBeTrue();

    // The client sees one row, and can still fetch the older version by id.
    $this->actingAs($this->client)->get('/client/documents')
        ->assertInertia(fn ($page) => $page->has('documents', 1));

    $this->actingAs($this->client)->get("/client/documents/{$first->id}/download")->assertOk();
});

it('does not store a document under a name the uploader chose', function () {
    Storage::fake('private');

    $this->actingAs($this->owner)->post('/admin/documents', [
        'file' => UploadedFile::fake()->create('../../escape.pdf', 10, 'application/pdf'),
        'client_id' => $this->client->id,
    ]);

    $document = Document::query()->sole();

    expect($document->path)->toStartWith("clients/{$this->client->id}/")
        ->and($document->path)->not->toContain('..');
});

/* --------------------------------------------------------------- support */

it('starts the response clock only on a reply the client can read', function () {
    $ticket = SupportTicket::query()->create([
        'client_id' => $this->client->id,
        'subject' => 'Something broke',
        'body' => 'It is broken.',
        'response_due_at' => now()->addHours(8),
    ]);

    $this->actingAs($this->owner)->post("/admin/tickets/{$ticket->id}/reply", [
        'body' => 'Looking at it now.',
        'is_internal' => true,
    ])->assertRedirect();

    expect($ticket->fresh()->first_response_at)->toBeNull();

    $this->actingAs($this->owner)->post("/admin/tickets/{$ticket->id}/reply", [
        'body' => 'We have found it and a fix is on the way.',
    ]);

    expect($ticket->fresh()->first_response_at)->not->toBeNull();
});

it('keeps an internal note out of what the client sees', function () {
    $ticket = SupportTicket::query()->create([
        'client_id' => $this->client->id,
        'subject' => 'Something broke',
        'body' => 'It is broken.',
    ]);

    $this->actingAs($this->owner)->post("/admin/tickets/{$ticket->id}/reply", [
        'body' => 'Their server is out of disk again.',
        'is_internal' => true,
    ]);

    $this->actingAs($this->client)->get("/client/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page->has('messages', 0));
});

it('will not delete a contract that has tickets against it', function () {
    $contract = MaintenanceContract::query()->create([
        'client_id' => $this->client->id,
        'plan' => 'Standard',
        'starts_on' => today()->subMonth(),
        'ends_on' => today()->addYear(),
        'amount' => 0,
    ]);

    SupportTicket::query()->create([
        'client_id' => $this->client->id,
        'contract_id' => $contract->id,
        'subject' => 'Raised against it',
        'body' => 'Body.',
    ]);

    $this->actingAs($this->owner)->delete("/admin/contracts/{$contract->id}")
        ->assertSessionHasErrors('contract');

    expect(MaintenanceContract::query()->count())->toBe(1);
});

/* -------------------------------------------------------------- API keys */

it('shows an API key once and never stores it in the clear', function () {
    $plan = ApiKeyPlan::query()->create([
        'name' => 'Starter',
        'slug' => 'starter',
        'monthly_quota' => 1000,
        'rate_limit_per_minute' => 60,
        'price' => 0,
    ]);

    $response = $this->actingAs($this->client)->post('/client/api-keys', [
        'label' => 'Website',
        'plan_id' => $plan->id,
        'environment' => 'test',
    ]);

    $key = ApiKey::query()->sole();
    $secret = session('issuedApiKey')['key'];

    expect($secret)->toStartWith('ubs_test_')
        ->and($key->key_hash)->toBe(hash('sha256', $secret))
        ->and($key->getAttributes())->not->toHaveKey('key')
        ->and($key->masked())->not->toContain($secret);

    // The database holds a hash, so finding it needs the key itself.
    expect(ApiKey::findByKey($secret)?->id)->toBe($key->id);
});

it('actually delivers the one readable copy to the browser', function () {
    /*
     * Flashing a payload is not enough: anything meant for a single render has
     * to be listed in HandleInertiaRequests::share or it never leaves the
     * server. This was missed in Phase 1 and missed again here, so it is a test
     * rather than a note.
     */
    $plan = ApiKeyPlan::query()->create([
        'name' => 'Starter',
        'slug' => 'starter',
        'monthly_quota' => 1000,
        'rate_limit_per_minute' => 60,
        'price' => 0,
    ]);

    $this->actingAs($this->client)->post('/client/api-keys', [
        'label' => 'Website',
        'plan_id' => $plan->id,
        'environment' => 'test',
    ]);

    $this->actingAs($this->client)->get('/client/api-keys')
        ->assertInertia(fn ($page) => $page->has('flash.issuedApiKey.key'));
});

it('raises a payment request for a live key on a paid plan', function () {
    $plan = ApiKeyPlan::query()->create([
        'name' => 'Growth',
        'slug' => 'growth',
        'monthly_quota' => 100000,
        'rate_limit_per_minute' => 600,
        'price' => Money::toPaise(4000),
    ]);

    $this->actingAs($this->client)->post('/client/api-keys', [
        'label' => 'Production',
        'plan_id' => $plan->id,
        'environment' => 'live',
    ])->assertRedirect();

    expect(ApiKey::query()->count())->toBe(0)
        ->and($this->client->paymentRequests()->count())->toBe(1);
});

it('issues a test key free even on a paid plan', function () {
    $plan = ApiKeyPlan::query()->create([
        'name' => 'Growth',
        'slug' => 'growth',
        'monthly_quota' => 100000,
        'rate_limit_per_minute' => 600,
        'price' => Money::toPaise(4000),
    ]);

    $this->actingAs($this->client)->post('/client/api-keys', [
        'label' => 'Sandbox',
        'plan_id' => $plan->id,
        'environment' => 'test',
    ]);

    expect(ApiKey::query()->count())->toBe(1)
        ->and($this->client->paymentRequests()->count())->toBe(0);
});

it('kills the old key the moment one is rotated', function () {
    $plan = ApiKeyPlan::query()->create([
        'name' => 'Starter',
        'slug' => 'starter',
        'monthly_quota' => 1000,
        'rate_limit_per_minute' => 60,
        'price' => 0,
    ]);

    $this->actingAs($this->client)->post('/client/api-keys', [
        'label' => 'Website',
        'plan_id' => $plan->id,
        'environment' => 'test',
    ]);

    $original = ApiKey::query()->sole();
    $originalSecret = session('issuedApiKey')['key'];

    $this->actingAs($this->client)->post("/client/api-keys/{$original->id}/rotate")->assertRedirect();

    expect($original->fresh()->status)->toBe('rotated')
        ->and($original->fresh()->isUsable())->toBeFalse()
        ->and(ApiKey::findByKey($originalSecret)->isUsable())->toBeFalse()
        ->and(ApiKey::query()->active()->count())->toBe(1);
});

it('will not rotate somebody else’s key', function () {
    $stranger = User::factory()->client()->create();
    $generated = ApiKey::generate('live');

    $theirs = ApiKey::query()->create([
        'client_id' => $stranger->id,
        'label' => 'Theirs',
        'key_prefix' => $generated['prefix'],
        'key_hash' => $generated['hash'],
        'last_four' => $generated['lastFour'],
    ]);

    $this->actingAs($this->client)->post("/client/api-keys/{$theirs->id}/rotate")->assertNotFound();
});

/* ------------------------------------------------------------- rendering */

it('renders every admin delivery screen, with rows in each', function () {
    $project = adminProject($this->client);
    $project->milestones()->create(['title' => 'Design sign off']);
    $project->updates()->create(['body' => 'A note.']);

    $proposal = Proposal::query()->create([
        'client_id' => $this->client->id,
        'title' => 'Phase two',
        'status' => 'draft',
    ]);
    $proposal->quotation()->create(['client_id' => $this->client->id]);

    $contract = MaintenanceContract::query()->create([
        'client_id' => $this->client->id,
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
    app(Invoicer::class)->issueFor($payment);

    Subscription::query()->create([
        'client_id' => $this->client->id,
        'name' => 'Managed hosting',
        'amount' => Money::toPaise(18000),
        'interval' => 'quarterly',
        'starts_on' => today()->subMonths(3),
        'renews_on' => today()->addDays(20),
    ]);

    ApiKeyPlan::query()->create([
        'name' => 'Starter',
        'slug' => 'starter',
        'monthly_quota' => 1000,
        'rate_limit_per_minute' => 60,
        'price' => 0,
    ]);

    $paths = [
        '/admin/projects', '/admin/projects/new', "/admin/projects/{$project->id}",
        "/admin/projects/{$project->id}/edit",
        '/admin/proposals', '/admin/proposals/new', "/admin/proposals/{$proposal->id}",
        '/admin/documents',
        '/admin/tickets', "/admin/tickets/{$ticket->id}",
        '/admin/contracts', '/admin/contracts/new', "/admin/contracts/{$contract->id}/edit",
        '/admin/billing', '/admin/billing/new', "/admin/billing/{$payment->id}",
        '/admin/invoices', '/admin/subscriptions', '/admin/api-plans',
    ];

    foreach ($paths as $path) {
        $this->actingAs($this->owner)->get($path)->assertOk();
    }
});

it('keeps a staff account out of delivery it was not granted', function () {
    $this->seed(PermissionSeeder::class);

    $staff = User::factory()->admin()->create();
    $staff->permissions()->attach(Permission::query()->where('key', 'projects.view')->sole());

    $this->actingAs($staff->fresh())->get('/admin/projects')->assertOk();
    $this->actingAs($staff->fresh())->get('/admin/billing')->assertForbidden();
    $this->actingAs($staff->fresh())->get('/admin/tickets')->assertForbidden();
    $this->actingAs($staff->fresh())->get('/admin/projects/new')->assertForbidden();
});

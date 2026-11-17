<?php

use App\Models\AuditLog;
use App\Models\ClientMember;
use App\Models\Conversation;
use App\Models\MaintenanceContract;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\ClientInvitation;
use App\Notifications\PaymentRequested;
use App\Notifications\ProposalSent;
use App\Notifications\WelcomeCredentials;
use App\Services\Billing\Invoicer;
use App\Services\Client\Organisation;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.prefix', 'UBS', 'invoicing');

    $this->owner = User::factory()->owner()->create();
    $this->acme = User::factory()->client()->create(['name' => 'Priya Acme', 'email' => 'priya@acme.test']);
    $this->acme->profile()->updateOrCreate([], ['company' => 'Acme Logistics']);

    $this->join = function (string $name, string $role) {
        $person = User::factory()->client()->create(['name' => $name, 'email' => str($name)->slug().'@acme.test']);
        ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $person->id, 'role' => $role, 'joined_at' => now()]);

        return $person;
    };

    $this->finance = ($this->join)('Farah Finance', 'finance');
    $this->tech = ($this->join)('Tariq Tech', 'technical');
    $this->signatory = ($this->join)('Sameer Sign', 'signatory');

    $this->project = Project::query()->create(['client_id' => $this->acme->id, 'name' => 'Warehouse system', 'status' => 'in_progress']);
    $this->stranger = User::factory()->client()->create();
    $this->theirs = Project::query()->create(['client_id' => $this->stranger->id, 'name' => 'Not Acme', 'status' => 'in_progress']);
});

function orgPayment(User $account, int $rupees = 50000): PaymentRequest
{
    $request = PaymentRequest::query()->create(['user_id' => $account->id, 'title' => 'Milestone two']);
    $request->price(Money::toPaise($rupees), 18)->save();

    return $request;
}

/* --------------------------------------------------- one rule, widened */

it('shows every person in the organisation its records, and nobody else\'s', function () {
    foreach ([$this->acme, $this->finance, $this->tech, $this->signatory] as $person) {
        $this->actingAs($person)->get("/client/projects/{$this->project->id}")->assertOk();
        $this->actingAs($person)->get("/client/projects/{$this->theirs->id}")->assertNotFound();
    }

    $this->actingAs($this->tech)->get('/client/projects')
        ->assertInertia(fn ($page) => $page->has('table.rows', 1)->where('table.rows.0.name', 'Warehouse system'));
});

it('keeps the person who raised a ticket, and shows it to the whole organisation', function () {
    MaintenanceContract::query()->create([
        'client_id' => $this->acme->id, 'plan' => 'Care', 'reference' => 'MC-1', 'starts_on' => today()->subMonth(),
        'ends_on' => today()->addYear(), 'response_hours' => 8, 'resolution_hours' => 48, 'status' => 'active',
    ]);

    $this->actingAs($this->tech)->post('/client/tickets', [
        'subject' => 'Export fails', 'body' => 'The CSV export times out.', 'priority' => 'high',
    ])->assertRedirect();

    $ticket = SupportTicket::query()->sole();
    expect($ticket->requester_id)->toBe($this->tech->id)
        ->and($ticket->contract_id)->not->toBeNull();

    $this->actingAs($this->acme)->get("/client/tickets/{$ticket->id}")->assertOk();
    $this->actingAs($this->finance)->get("/client/tickets/{$ticket->id}")->assertOk();
    $this->actingAs($this->stranger)->get("/client/tickets/{$ticket->id}")->assertNotFound();
});

it('searches the organisation\'s records for a member', function () {
    $this->actingAs($this->finance)->getJson('/search?q=Warehouse')
        ->assertOk()
        ->assertJsonFragment(['href' => "/client/projects/{$this->project->id}"]);
});

/* ------------------------------------------------------------- roles */

it('lets each role do its own part, and says who can when it cannot', function () {
    $payment = orgPayment($this->acme);
    app(Invoicer::class)->issueFor($payment);

    $this->actingAs($this->tech)->postJson("/client/payments/{$payment->id}/checkout")
        ->assertForbidden()
        ->assertJsonPath('message', 'Only finance or an owner in your organisation can pay.');
    expect($this->actingAs($this->finance)->postJson("/client/payments/{$payment->id}/checkout")->getStatusCode())->not->toBe(403);

    $proposal = Proposal::query()->create([
        'client_id' => $this->acme->id, 'title' => 'Driver app', 'status' => 'sent', 'sent_at' => now(), 'valid_until' => today()->addWeek(),
    ]);
    $this->actingAs($this->finance)->post("/client/proposals/{$proposal->id}/respond", ['response' => 'accepted'])->assertForbidden();
    expect($proposal->fresh()->status)->toBe('sent');
    $this->actingAs($this->signatory)->post("/client/proposals/{$proposal->id}/respond", ['response' => 'accepted'])->assertRedirect();
    expect($proposal->fresh()->status)->toBe('accepted');

    $this->actingAs($this->finance)->post('/client/api-keys', ['plan_id' => 1, 'label' => 'x', 'environment' => 'test'])->assertForbidden();

    // What the screens are told, so they can leave out a button.
    $this->actingAs($this->tech)->get('/client')
        ->assertInertia(fn ($page) => $page
            ->where('auth.client.role', 'technical')
            ->where('auth.client.organisation', 'Acme Logistics')
            ->where('auth.client.may', ['api_keys', 'feedback']));
    $this->actingAs($this->acme)->get('/client')
        ->assertInertia(fn ($page) => $page->where('auth.client.role', 'owner')->where('auth.client.may', fn ($may) => count($may) === 7));
});

it('sends invoices to finance and proposals to signatories, with owners always told', function () {
    // Raised against a member by mistake, it is still the organisation's.
    $this->actingAs($this->owner)->post('/admin/billing', [
        'user_id' => $this->tech->id, 'title' => 'Milestone two', 'amount' => 50000, 'tax_rate' => 18,
    ])->assertRedirect();

    $payment = PaymentRequest::query()->latest('id')->first();
    expect($payment->user_id)->toBe($this->acme->id);
    Notification::assertSentTo([$this->finance, $this->acme], PaymentRequested::class);
    Notification::assertNotSentTo([$this->tech, $this->signatory], PaymentRequested::class);

    $proposal = Proposal::query()->create(['client_id' => $this->acme->id, 'title' => 'Driver app', 'status' => 'draft', 'valid_until' => today()->addWeek()]);
    $this->actingAs($this->owner)->post("/admin/proposals/{$proposal->id}/send");
    Notification::assertSentTo([$this->signatory, $this->acme], ProposalSent::class);
    Notification::assertNotSentTo([$this->finance, $this->tech], ProposalSent::class);

    expect(collect($this->acme->clientRecipients('technical'))->pluck('id')->all())->toBe([$this->tech->id])
        ->and($this->acme->clientRecipients())->toHaveCount(4);

    // Staff are offered the organisation, not its members, when they bill or propose.
    expect(User::query()->clientAccounts()->pluck('id')->all())->not->toContain($this->tech->id)
        ->toContain($this->acme->id);
});

/* ------------------------------------------------------- bringing in */

it('answers the same for a new address, an existing account and one that cannot be added', function () {
    $empty = User::factory()->client()->create(['email' => 'empty@other.test']);
    User::factory()->student()->create(['email' => 'student@other.test']);

    $answers = collect(['new@acme.test', 'empty@other.test', 'student@other.test', $this->stranger->email])
        ->map(function (string $email) {
            $response = $this->actingAs($this->acme)->post('/client/people', ['name' => 'Somebody', 'email' => $email, 'role' => 'technical']);

            return [$response->getStatusCode(), $response->headers->get('Location'), str(session('success'))->replace($email, 'EMAIL')->toString()];
        });

    expect($answers->unique()->count())->toBe(1);

    // A new address has an account and is in.
    $new = User::query()->where('email', 'new@acme.test')->sole();
    expect($new->clientMembership->joined_at)->not->toBeNull()
        ->and($new->clientAccount()->is($this->acme))->toBeTrue();
    Notification::assertSentTo($new, WelcomeCredentials::class, fn ($n) => str_contains($n->personalNote, 'Acme Logistics'));

    // An existing empty account is asked, and is not in until it says yes.
    $invite = ClientMember::query()->where('user_id', $empty->id)->sole();
    expect($invite->joined_at)->toBeNull()->and($empty->fresh()->clientAccount()->is($empty))->toBeTrue();
    Notification::assertSentTo($empty, ClientInvitation::class);

    $this->actingAs($empty)->get('/client')->assertInertia(fn ($page) => $page->where('invitations.0.organisation', 'Acme Logistics'));
    $this->actingAs($empty)->get("/client/projects/{$this->project->id}")->assertNotFound();
    $this->actingAs($empty)->post("/client/invitations/{$invite->id}/accept")->assertRedirect('/client');
    $this->actingAs($empty->fresh())->get("/client/projects/{$this->project->id}")->assertOk();

    // A student, or a client with work of their own, is not added at all.
    expect(ClientMember::query()->whereIn('user_id', User::query()->whereIn('email', ['student@other.test', $this->stranger->email])->pluck('id'))->count())->toBe(0);
});

it('lets only an owner bring people in, change roles and take them out', function () {
    $this->actingAs($this->finance)->post('/client/people', ['name' => 'X', 'email' => 'x@acme.test', 'role' => 'owner'])->assertForbidden();
    expect(User::query()->where('email', 'x@acme.test')->exists())->toBeFalse();

    $membership = $this->tech->clientMembership;
    $this->actingAs($this->acme)->put("/client/people/{$membership->id}", ['role' => 'signatory'])->assertSessionHas('success');
    expect($membership->fresh()->role)->toBe('signatory');

    // A member made owner can manage, but not change or remove themselves.
    $this->actingAs($this->acme)->put("/client/people/{$this->finance->clientMembership->id}", ['role' => 'owner']);
    $this->actingAs($this->finance->fresh())->put("/client/people/{$this->finance->clientMembership->id}", ['role' => 'finance'])->assertStatus(422);

    $this->actingAs($this->acme)->delete("/client/people/{$membership->id}")->assertSessionHas('success');
    $this->actingAs($this->tech->fresh())->get("/client/projects/{$this->project->id}")->assertNotFound();

    expect(AuditLog::query()->whereIn('action', ['client.member_role_changed', 'client.member_removed'])->count())->toBe(3);

    // Another organisation's membership is not found, not refused.
    $otherMember = User::factory()->client()->create();
    $foreign = ClientMember::query()->create(['account_id' => $this->stranger->id, 'user_id' => $otherMember->id, 'role' => 'technical', 'joined_at' => now()]);
    $this->actingAs($this->acme)->delete("/client/people/{$foreign->id}")->assertNotFound();
});

it('puts the organisation\'s people in its chat threads, and takes leavers out', function () {
    $this->actingAs($this->tech)->get('/chat')->assertOk();

    $room = Conversation::query()->where('type', 'client_direct')->where('client_id', $this->acme->id)->whereNull('project_id')->sole();
    expect($room->includes($this->tech))->toBeTrue()
        ->and($room->includes($this->finance))->toBeTrue();

    app(Organisation::class)->remove($this->tech->clientMembership, $this->acme);
    expect($room->fresh()->includes($this->tech))->toBeFalse();
});

/* ------------------------------------------------------------- staff */

it('lets staff see and manage an organisation, told what actually happened', function () {
    $this->actingAs($this->owner)->get("/admin/clients/{$this->tech->id}")
        ->assertInertia(fn ($page) => $page
            ->where('organisation.name', 'Acme Logistics')
            ->where('organisation.isAccount', false)
            ->where('organisation.role', 'technical')
            ->has('organisation.members', 3));

    $this->actingAs($this->owner)->post("/admin/clients/{$this->acme->id}/members", ['name' => 'New Person', 'email' => 'newperson@acme.test', 'role' => 'finance'])
        ->assertSessionHas('success', fn ($text) => str_contains($text, 'Account made'));
    $this->actingAs($this->owner)->post("/admin/clients/{$this->acme->id}/members", ['name' => 'Them', 'email' => $this->stranger->email, 'role' => 'finance'])
        ->assertSessionHas('error');

    $this->actingAs($this->owner)->get('/admin/clients')
        ->assertInertia(fn ($page) => $page->where('table.rows', fn ($rows) => collect($rows)->firstWhere('email', $this->tech->email)['organisation'] === 'Acme Logistics (technical)'));
});

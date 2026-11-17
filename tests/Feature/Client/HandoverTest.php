<?php

use App\Models\ClientMember;
use App\Models\Handover;
use App\Models\Permission;
use App\Models\Project;
use App\Models\SatisfactionSurvey;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\Testimonial;
use App\Models\User;
use App\Notifications\HandoverNotice;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->admin()->create();
    $this->manager->permissions()->attach(Permission::query()->whereIn('key', ['projects.view', 'projects.manage', 'support.manage'])->get());

    $this->acme = User::factory()->client()->create(['name' => 'Priya Acme']);
    $this->acme->profile()->updateOrCreate([], ['company' => 'Acme Logistics']);
    $member = function (string $role) {
        $user = User::factory()->client()->create();
        ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    };
    $this->signatory = $member('signatory');
    $this->technical = $member('technical');
    $this->stranger = User::factory()->client()->create();

    $this->project = Project::query()->create(['client_id' => $this->acme->id, 'name' => 'Warehouse system', 'status' => 'delivered', 'manager_id' => $this->manager->id]);
    $this->pack = [
        'repository' => 'github.com/acme/warehouse, owner access transferred to Rahul.',
        'deployment_guide' => "## Steps\n\n1. Pull\n2. Migrate",
        'environment_notes' => 'Production on their own server.',
        'credentials_note' => 'Hosting and registrar access handed over in person on 12 Nov.',
        'licences' => [['name' => 'Map tiles', 'detail' => 'In their name']],
        'videos' => [['title' => 'Running a wave', 'url' => 'https://videos.example.com/wave']],
        'warranty_ends_on' => today()->addMonths(3)->toDateString(),
    ];
    $this->save = fn (array $overrides = [], bool $publish = false) => $this->actingAs($this->manager)
        ->put("/admin/projects/{$this->project->id}/handover", [...$this->pack, ...$overrides, 'publish' => $publish]);
    $this->client = "/client/projects/{$this->project->id}/handover";
});

it('refuses anything that looks like a credential, and never shows a draft', function (string $field, string $secret) {
    ($this->save)([$field => "See below.\n{$secret}"])->assertSessionHasErrors('handover');
    expect(Handover::query()->count())->toBe(0);
})->with([
    ['credentials_note', 'password: Hunter2!'],
    ['environment_notes', 'DB_PASSWORD=s3cret'],
    ['repository', 'token = ghp_abcdefghijklmnopqrstuvwxyz0123456789'],
    ['deployment_guide', 'AKIAABCDEFGHIJKLMNOP'],
    ['environment_notes', "-----BEGIN RSA PRIVATE KEY-----\nMIIE"],
]);

it('accepts saying where access was shared', function () {
    ($this->save)(['credentials_note' => "API token: shared in the client's 1Password vault.\nPasswordless sign in is on."])->assertSessionHas('success');
});

it('publishes a revision the client acknowledges, and keeps what they were shown', function () {
    ($this->save)()->assertSessionHas('success');
    $this->actingAs($this->signatory)->get($this->client)->assertNotFound();

    ($this->save)(publish: true)->assertSessionHas('success');
    Notification::assertSentTo([$this->acme, $this->signatory, $this->technical], HandoverNotice::class, fn ($notice) => $notice->what === 'published');

    $this->actingAs($this->technical)->get($this->client)->assertOk()
        ->assertInertia(fn ($page) => $page->component('client/projects/Handover')
            ->where('handover.revision', 1)
            ->where('handover.licences.0.name', 'Map tiles')
            ->where('canAcknowledge', false)
            ->where('handover.deploymentGuide', fn ($html) => str_contains($html, '<h2')));
    $this->actingAs($this->stranger)->get($this->client)->assertNotFound();

    $this->actingAs($this->technical)->post("{$this->client}/acknowledge")->assertForbidden();
    $this->actingAs($this->signatory)->post("{$this->client}/acknowledge")->assertSessionHas('success');
    Notification::assertSentTo($this->manager, HandoverNotice::class, fn ($notice) => $notice->what === 'acknowledged');

    // Edits after publishing are not the client's until published again.
    ($this->save)(['repository' => 'Moved to gitlab.com/acme/warehouse.']);
    $this->actingAs($this->signatory)->get($this->client)
        ->assertInertia(fn ($page) => $page->where('handover.repository', 'github.com/acme/warehouse, owner access transferred to Rahul.')->where('handover.acknowledged', true));

    ($this->save)(['repository' => 'Moved to gitlab.com/acme/warehouse.'], publish: true);
    $this->actingAs($this->signatory)->get($this->client)
        ->assertInertia(fn ($page) => $page->where('handover.revision', 2)->where('handover.acknowledged', false)->where('canAcknowledge', true));
    expect(collect($this->actingAs($this->signatory)->get('/client')->viewData('page')['props']['waitingOnYou'])->pluck('kind'))->toContain('handover');
});

it('asks a short survey after handover, and drafts a testimonial only with consent', function () {
    ($this->save)(publish: true);
    $this->actingAs($this->signatory)->post("{$this->client}/acknowledge");

    $this->actingAs($this->signatory)->post("{$this->client}/survey", ['rating' => 5, 'quote' => 'They shipped on time.', 'consent_to_publish' => true])
        ->assertSessionHasErrors('credit_as');
    $this->actingAs($this->signatory)->post("{$this->client}/survey", [
        'rating' => 5, 'went_well' => 'Clear updates.', 'quote' => 'They shipped on time and told us everything.', 'consent_to_publish' => true, 'credit_as' => 'Meera Iyer, Acme Logistics',
    ])->assertSessionHas('success');
    $this->actingAs($this->signatory)->post("{$this->client}/survey", ['rating' => 1])->assertSessionHas('error');
    $this->actingAs($this->technical)->post("{$this->client}/survey", ['rating' => 4, 'quote' => 'Good', 'consent_to_publish' => false])->assertSessionHas('success');

    [$consented, $private] = SatisfactionSurvey::query()->orderBy('id')->get()->all();
    expect($consented->consented_at)->not->toBeNull()
        ->and($private->consent_to_publish)->toBeFalse();

    $this->actingAs($this->manager)->get('/admin/satisfaction')->assertOk()->assertInertia(fn ($page) => $page->has('table.rows', 2)->where('canMakeTestimonials', false));
    $this->actingAs($this->manager)->post("/admin/satisfaction/{$consented->id}/testimonial")->assertForbidden();

    $this->actingAs($this->owner)->post("/admin/satisfaction/{$private->id}/testimonial")->assertStatus(422);
    $this->actingAs($this->owner)->post("/admin/satisfaction/{$consented->id}/testimonial")->assertSessionHas('success');
    $this->actingAs($this->owner)->post("/admin/satisfaction/{$consented->id}/testimonial")->assertStatus(422);

    $testimonial = Testimonial::query()->sole();
    expect($testimonial->is_published)->toBeFalse()
        ->and($testimonial->author_name)->toBe('Meera Iyer, Acme Logistics')
        ->and($testimonial->quote)->toBe('They shipped on time and told us everything.');
});

it('keeps a word with a ticket rating', function () {
    $ticket = SupportTicket::query()->create(['client_id' => $this->acme->id, 'requester_id' => $this->signatory->id, 'reference' => 'TKT-1', 'subject' => 'Login', 'body' => 'Cannot sign in', 'status' => 'resolved']);

    $this->actingAs($this->signatory)->post("/client/tickets/{$ticket->id}/close", ['satisfaction' => 4, 'satisfaction_comment' => 'Quick, thank you.'])->assertSessionHas('success');

    expect($ticket->fresh()->satisfaction_comment)->toBe('Quick, thank you.');
    $this->actingAs($this->manager)->get('/admin/satisfaction')->assertInertia(fn ($page) => $page->where('tickets.count', 1)->where('tickets.average', 4));
});

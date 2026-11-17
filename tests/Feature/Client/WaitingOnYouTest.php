<?php

use App\Models\Agreement;
use App\Models\ClientMember;
use App\Models\Document;
use App\Models\MilestoneSignoff;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaitingItem;
use App\Notifications\WaitingItemAnswered;
use App\Notifications\WaitingOnYou;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->admin()->create();
    $this->manager->permissions()->attach(Permission::query()->whereIn('key', ['projects.view', 'projects.manage'])->get());

    $this->acme = User::factory()->client()->create(['name' => 'Priya Acme']);
    $this->acme->profile()->updateOrCreate([], ['company' => 'Acme Logistics']);
    $member = function (string $role) {
        $user = User::factory()->client()->create();
        ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    };
    $this->signatory = $member('signatory');
    $this->finance = $member('finance');
    $this->technical = $member('technical');
    $this->stranger = User::factory()->client()->create();

    $this->project = Project::query()->create([
        'client_id' => $this->acme->id, 'name' => 'Warehouse system', 'status' => 'in_progress', 'manager_id' => $this->manager->id,
    ]);

    $this->ask = fn (array $overrides = []) => $this->actingAs($this->manager)->post("/admin/projects/{$this->project->id}/waiting", [
        'kind' => 'assets',
        'title' => 'Your logo as an SVG',
        'details' => 'And the brand colours, if you have them written down.',
        'due_on' => today()->addDays(5)->toDateString(),
        'blocks_work' => true,
        ...$overrides,
    ]);
    $this->reply = fn (WaitingItem $item, User $who, array $payload) => $this->actingAs($who)
        ->post("/client/projects/{$this->project->id}/waiting/{$item->id}/answer", $payload);
});

it('asks the people it concerns, and takes a reply with files from anybody in the organisation', function () {
    ($this->ask)()->assertSessionHas('success');
    $assets = WaitingItem::query()->sole();
    Notification::assertSentTo([$this->acme, $this->signatory, $this->finance, $this->technical], WaitingOnYou::class);

    Notification::fake();
    ($this->ask)(['kind' => 'decision', 'title' => 'Which warehouse goes first']);
    Notification::assertSentTo([$this->acme, $this->signatory], WaitingOnYou::class);
    Notification::assertNotSentTo([$this->finance, $this->technical], WaitingOnYou::class);

    Notification::fake();
    ($this->ask)(['kind' => 'access', 'title' => 'An account on your staging server']);
    Notification::assertSentTo($this->technical, WaitingOnYou::class);
    Notification::assertNotSentTo($this->finance, WaitingOnYou::class);

    $this->actingAs($this->finance)->get("/client/projects/{$this->project->id}/waiting/{$assets->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('client/projects/Waiting')->where('item.status', 'open')->where('item.events.0.action', 'raised'));

    ($this->reply)($assets, $this->finance, [
        'body' => 'Logo attached. Colours are in the PDF.',
        'files' => [UploadedFile::fake()->create('brand-guide.pdf', 120, 'application/pdf')],
    ])->assertSessionHas('success');

    $assets->refresh();
    $document = Document::query()->sole();

    expect($assets->status)->toBe('answered')
        ->and($assets->answered_by)->toBe($this->finance->id)
        ->and($document->client_id)->toBe($this->acme->id)
        ->and($document->project_id)->toBe($this->project->id)
        ->and($document->uploaded_by)->toBe($this->finance->id)
        ->and($document->category)->toBe('From the client')
        ->and($document->name)->toBe('brand-guide.pdf')
        ->and($assets->events->last()->document_ids)->toBe([$document->id]);

    Storage::disk('private')->assertExists($document->path);
    Notification::assertSentTo($this->manager, WaitingItemAnswered::class);
    $this->actingAs($this->signatory)->get("/client/documents/{$document->id}/download")->assertOk();

    $this->actingAs($this->manager)->get("/admin/projects/{$this->project->id}")
        ->assertInertia(fn ($page) => $page->where('waiting.2.status', 'answered')->where('waiting.2.events.1.files.0.name', 'brand-guide.pdf'));
});

it('refuses an empty reply, a file it does not take, a second reply and other organisations', function () {
    ($this->ask)();
    $item = WaitingItem::query()->sole();

    ($this->reply)($item, $this->signatory, ['body' => ' '])->assertSessionHasErrors(['body' => 'Write a reply or attach a file.']);
    ($this->reply)($item, $this->signatory, ['files' => [UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload')]])
        ->assertSessionHasErrors('files.0');

    $this->actingAs($this->stranger)->get("/client/projects/{$this->project->id}/waiting/{$item->id}")->assertNotFound();
    ($this->reply)($item, $this->stranger, ['body' => 'Not mine'])->assertNotFound();

    ($this->reply)($item, $this->signatory, ['body' => 'Sent by email.'])->assertSessionHas('success');
    ($this->reply)($item, $this->technical, ['body' => 'Again'])->assertSessionHasErrors(['body' => 'This is not waiting on you any more.']);

    expect(Document::query()->count())->toBe(0)
        ->and($item->events()->count())->toBe(2);
});

it('asks again saying what is missing, and counts the time work waited once, however many things it waited for', function () {
    $this->freezeTime();

    ($this->ask)(['title' => 'Logo']);
    ($this->ask)(['title' => 'Nice to have: old screenshots', 'blocks_work' => false]);
    [$logo, $screenshots] = WaitingItem::query()->orderBy('id')->get()->all();

    $this->travel(1)->days();
    ($this->ask)(['title' => 'Product photos']);
    $photos = WaitingItem::query()->latest('id')->first();

    $this->travel(1)->days();
    ($this->reply)($photos, $this->signatory, ['body' => 'Photos are in the shared folder.']);
    $this->travel(1)->days();
    ($this->reply)($logo, $this->signatory, ['body' => 'Here is a PNG.']);

    $this->travel(1)->days();
    Notification::fake();
    $this->actingAs($this->manager)->post("/admin/projects/{$this->project->id}/waiting/{$logo->id}/reopen", ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($this->manager)->post("/admin/projects/{$this->project->id}/waiting/{$logo->id}/reopen", ['note' => 'We need the SVG, not a PNG.'])
        ->assertSessionHas('success');
    Notification::assertSentTo($this->signatory, WaitingOnYou::class, fn ($notification) => $notification->reopened);
    expect($logo->fresh()->status)->toBe('open');

    $this->travel(2)->days();
    ($this->reply)($logo, $this->signatory, ['body' => 'SVG attached.', 'files' => [UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml')]])
        ->assertSessionHas('success');
    $this->actingAs($this->manager)->post("/admin/projects/{$this->project->id}/waiting/{$logo->id}/close")->assertSessionHas('success');

    // The logo waited three days, then two more: five. The photos waited
    // inside the first three and add nothing. The screenshots never held
    // work up and are not counted, though they still show their own time.
    $this->actingAs($this->manager)->get("/admin/projects/{$this->project->id}")
        ->assertInertia(fn ($page) => $page->where('waitingTime', '5 days')
            ->where('waiting', fn ($items) => collect($items)->firstWhere('id', $logo->id)['waited'] === '5 days'
                && collect($items)->firstWhere('id', $logo->id)['status'] === 'closed'
                && collect($items)->firstWhere('id', $screenshots->id)['waited'] === '6 days'));
    $this->actingAs($this->signatory)->get("/client/projects/{$this->project->id}")
        ->assertInertia(fn ($page) => $page->where('waitingTime', '5 days')->has('waiting', 2));

    // Finished is finished.
    $this->actingAs($this->manager)->post("/admin/projects/{$this->project->id}/waiting/{$logo->id}/cancel")
        ->assertSessionHas('error', 'This item is already finished.');
});

it('leads the dashboard with what needs them, and says whose it is when it is not theirs', function () {
    ($this->ask)(['title' => 'Logo', 'due_on' => today()->addDay()->toDateString()]);

    $milestone = $this->project->milestones()->create(['title' => 'Picking module', 'sort_order' => 1]);
    MilestoneSignoff::query()->create(['milestone_id' => $milestone->id, 'project_id' => $this->project->id, 'requested_by' => $this->manager->id, 'deliverables' => ['Screens']]);
    Agreement::query()->create(['client_id' => $this->acme->id, 'kind' => 'nda', 'title' => 'NDA', 'body' => 'Text', 'number' => 'UBS/A/2026/001', 'status' => 'sent', 'sent_at' => now()]);
    Proposal::query()->create(['client_id' => $this->acme->id, 'title' => 'Driver app', 'status' => 'sent', 'sent_at' => now(), 'valid_until' => today()->addMonth()]);
    $this->actingAs($this->owner)->post('/admin/billing', [
        'user_id' => $this->acme->id, 'title' => 'Advance', 'amount' => 50000, 'tax_rate' => 18, 'due_on' => today()->addDays(3)->toDateString(),
    ]);

    $actions = fn (User $who) => collect($this->actingAs($who)->get('/client')->viewData('page')['props']['waitingOnYou'])
        ->mapWithKeys(fn ($item) => [$item['kind'] => $item['action'].' | '.$item['detail']]);

    $finance = $actions($this->finance);
    expect($finance->keys()->first())->toBe('waiting')
        ->and($finance['waiting'])->toStartWith('Reply | Warehouse system · wanted by')
        ->and($finance['milestone'])->toBe('Read | Warehouse system · for a signatory to accept')
        ->and($finance['agreement'])->toBe('Read | UBS/A/2026/001 · for a signatory')
        ->and($finance['proposal'])->toEndWith('· for a signatory')
        ->and($finance['payment'])->toStartWith('Pay now');

    $signatory = $actions($this->signatory);
    expect($signatory['milestone'])->toStartWith('Review')
        ->and($signatory['agreement'])->toBe('Read and sign | UBS/A/2026/001')
        ->and($signatory['proposal'])->toStartWith('Read and sign')
        ->and($signatory['payment'])->toEndWith('· for finance');

    // An overdue invoice with its request still open is one debt, listed once.
    $this->travel(5)->days();
    $kinds = collect($this->actingAs($this->finance)->get('/client')->viewData('page')['props']['waitingOnYou'])->pluck('kind');
    expect($kinds->filter(fn ($kind) => $kind === 'payment')->count())->toBe(1)
        ->and($kinds)->not->toContain('overdue');
});

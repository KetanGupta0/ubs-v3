<?php

use App\Models\ClientMember;
use App\Models\FeedbackItem;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\FeedbackNotice;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');

    $this->manager = User::factory()->admin()->create();
    $this->manager->permissions()->attach(Permission::query()->whereIn('key', ['projects.view', 'projects.manage'])->get());

    $this->acme = User::factory()->client()->create(['name' => 'Priya Acme']);
    $this->acme->profile()->updateOrCreate([], ['company' => 'Acme Logistics']);
    $member = function (string $role) {
        $user = User::factory()->client()->create();
        ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    };
    $this->technical = $member('technical');
    $this->finance = $member('finance');
    $this->stranger = User::factory()->client()->create();

    $this->project = Project::query()->create(['client_id' => $this->acme->id, 'name' => 'Warehouse system', 'status' => 'in_progress', 'manager_id' => $this->manager->id]);
    $this->milestone = $this->project->milestones()->create(['title' => 'Picking module', 'sort_order' => 1]);
    $this->base = "/client/projects/{$this->project->id}/feedback";

    $this->report = fn (User $who, array $overrides = []) => $this->actingAs($who)->post($this->base, [
        'kind' => 'bug',
        'title' => 'Scanning a return shows an error',
        'details' => 'Scan any parcel marked returned; expected it to be accepted.',
        'page_url' => 'https://staging.example.com/returns',
        'milestone_id' => $this->milestone->id,
        'screenshots' => [UploadedFile::fake()->image('screen.png', 640, 400)],
        ...$overrides,
    ]);
    $this->move = fn (FeedbackItem $item, string $to, ?string $note = null) => $this->actingAs($this->manager)->post("/admin/feedback/{$item->id}/move", ['to' => $to, 'note' => $note]);
});

it('takes a report with a re-encoded screenshot from the technical people, linked to the milestone it holds up', function () {
    ($this->report)($this->finance)->assertForbidden();
    ($this->report)($this->technical, ['milestone_id' => Project::query()->create(['client_id' => $this->acme->id, 'name' => 'Other', 'status' => 'in_progress'])->milestones()->create(['title' => 'Elsewhere'])->id])
        ->assertSessionHasErrors('milestone_id');
    ($this->report)($this->technical, ['screenshots' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('screenshots.0');

    ($this->report)($this->technical)->assertRedirect();
    $item = FeedbackItem::query()->sole();

    expect($item->status)->toBe('new')
        ->and($item->milestone_id)->toBe($this->milestone->id)
        ->and($item->client_id)->toBe($this->acme->id)
        ->and($item->screenshots)->toHaveCount(1)
        // Drawn into a new image and written out as JPEG: nothing that came with the file survives.
        ->and(substr(Storage::disk('private')->get($item->screenshots[0]), 0, 3))->toBe("\xFF\xD8\xFF");
    Notification::assertSentTo($this->manager, FeedbackNotice::class, fn ($notification) => $notification->what === 'new');

    $this->actingAs($this->finance)->get("{$this->base}/{$item->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('client/projects/FeedbackItem')->where('canComment', false)->has('item.screenshots', 1));
    $this->actingAs($this->finance)->get("{$this->base}/{$item->id}/screenshots/0")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs($this->manager)->get("/admin/feedback/{$item->id}/screenshots/0")->assertOk();
    $this->actingAs($this->stranger)->get("{$this->base}/{$item->id}/screenshots/0")->assertNotFound();
    $this->actingAs($this->stranger)->get("{$this->base}/{$item->id}")->assertNotFound();

    $this->actingAs($this->technical)->get("/client/projects/{$this->project->id}/milestones/{$this->milestone->id}")
        ->assertInertia(fn ($page) => $page->where('openFeedback.0.title', 'Scanning a return shows an error'));
    $this->actingAs($this->manager)->get("/admin/projects/{$this->project->id}")
        ->assertInertia(fn ($page) => $page->where('milestones.0.openFeedback', 1));
});

it('moves along on our side, and the client confirms the fix or sends it back', function () {
    ($this->report)($this->technical);
    $item = FeedbackItem::query()->sole();

    ($this->move)($item, 'acknowledged')->assertSessionHas('success');
    ($this->move)($item, 'acknowledged')->assertSessionHasErrors('note');
    ($this->move)($item, 'declined')->assertSessionHasErrors(['note' => 'Say why it is not changing.']);
    ($this->move)($item, 'in_progress');

    Notification::fake();
    ($this->move)($item, 'fixed', 'Returns are accepted now.');
    Notification::assertSentTo($this->technical, FeedbackNotice::class, fn ($notification) => $notification->what === 'fixed');

    $kinds = fn (User $who) => collect($this->actingAs($who)->get('/client')->viewData('page')['props']['waitingOnYou'])->pluck('kind');
    expect($kinds($this->technical))->toContain('feedback')
        ->and($kinds($this->finance))->not->toContain('feedback');

    $this->actingAs($this->technical)->post("{$this->base}/{$item->id}/confirm", ['works' => false])->assertSessionHasErrors('note');
    $this->actingAs($this->technical)->post("{$this->base}/{$item->id}/confirm", ['works' => false, 'note' => 'Still fails on the older handheld.'])->assertSessionHas('success');
    expect($item->fresh()->status)->toBe('in_progress');
    Notification::assertSentTo($this->manager, FeedbackNotice::class, fn ($notification) => $notification->what === 'reopened');

    ($this->move)($item, 'fixed');
    $this->actingAs($this->technical)->post("{$this->base}/{$item->id}/confirm", ['works' => true])->assertSessionHas('success');
    expect($item->fresh()->status)->toBe('closed')
        ->and($item->fresh()->closed_at)->not->toBeNull()
        ->and($item->events()->pluck('status')->filter()->values()->all())->toBe(['new', 'acknowledged', 'in_progress', 'fixed', 'in_progress', 'fixed', 'closed']);

    // Finished is finished.
    $this->actingAs($this->technical)->post("{$this->base}/{$item->id}/comment", ['body' => 'One more thing'])->assertSessionHasErrors('body');
    ($this->move)($item, 'in_progress')->assertSessionHasErrors('note');
});

it('keeps a thread both sides write in, and tells the other side', function () {
    ($this->report)($this->technical);
    $item = FeedbackItem::query()->sole();

    Notification::fake();
    $this->actingAs($this->manager)->post("/admin/feedback/{$item->id}/comment", ['body' => 'Which handheld model?'])->assertSessionHas('success');
    Notification::assertSentTo($this->technical, FeedbackNotice::class, fn ($notification) => $notification->what === 'comment');

    $this->actingAs($this->finance)->post("{$this->base}/{$item->id}/comment", ['body' => 'Not mine to answer'])->assertForbidden();
    $this->actingAs($this->technical)->post("{$this->base}/{$item->id}/comment", ['body' => 'The Zebra TC21.'])->assertSessionHas('success');
    Notification::assertSentTo($this->manager, FeedbackNotice::class, fn ($notification) => $notification->what === 'comment');

    $this->actingAs($this->manager)->get("/admin/feedback/{$item->id}")
        ->assertInertia(fn ($page) => $page->where('item.events.1.body', 'Which handheld model?')->where('item.events.2.body', 'The Zebra TC21.')->has('moves', 4));
});

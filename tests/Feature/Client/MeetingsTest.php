<?php

use App\Models\ClientMember;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaitingItem;
use App\Notifications\MeetingNotice;
use App\Notifications\MinutesAnswered;
use App\Notifications\MinutesPublished;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->admin()->create();
    $this->manager->permissions()->attach(Permission::query()->whereIn('key', ['projects.view', 'projects.manage'])->get());

    $this->acme = User::factory()->client()->create(['name' => 'Priya Acme']);
    $this->acme->profile()->updateOrCreate([], ['company' => 'Acme Logistics']);
    $this->technical = User::factory()->client()->create();
    ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $this->technical->id, 'role' => 'technical', 'joined_at' => now()]);
    $this->stranger = User::factory()->client()->create();

    $this->project = Project::query()->create(['client_id' => $this->acme->id, 'name' => 'Warehouse system', 'status' => 'in_progress']);

    $this->schedule = fn (array $overrides = []) => $this->actingAs($this->manager)->post('/admin/meetings', [
        'client_id' => $this->acme->id,
        'project_id' => $this->project->id,
        'title' => 'Kick-off call',
        'agenda' => 'Scope, people, dates.',
        'starts_at' => now()->addDays(2)->setTime(16, 0)->format('Y-m-d\TH:i'),
        'duration_minutes' => 60,
        'link' => 'https://meet.example.com/abc',
        ...$overrides,
    ]);

    $this->minutes = fn (Meeting $meeting, array $overrides = [], bool $publish = false) => $this->actingAs($this->manager)->put("/admin/meetings/{$meeting->id}/minutes", [
        'attendees' => ['Priya (Acme)', 'Ketan (Unboundbyte)'],
        'body' => "## Decided\n\n- Picking goes first.",
        'client_actions' => [['title' => 'Send the floor plans', 'due_on' => today()->addDays(3)->toDateString()]],
        'team_actions' => [['text' => 'Share the prototype', 'owner' => 'Ketan']],
        'publish' => $publish,
        ...$overrides,
    ]);
});

it('schedules a meeting, tells the organisation, and keeps them told when it moves or is called off', function () {
    ($this->schedule)(['project_id' => Project::query()->create(['client_id' => $this->stranger->id, 'name' => 'Theirs', 'status' => 'in_progress'])->id])
        ->assertSessionHasErrors('project_id');
    ($this->schedule)(['link' => 'http://meet.example.com/abc'])->assertSessionHasErrors('link');

    ($this->schedule)()->assertRedirect();
    $meeting = Meeting::query()->sole();
    Notification::assertSentTo([$this->acme, $this->technical], MeetingNotice::class, fn ($notification) => $notification->what === 'scheduled');

    $this->actingAs($this->technical)->get('/client/meetings')
        ->assertInertia(fn ($page) => $page->component('client/meetings/Index')->has('upcoming', 1)->where('upcoming.0.title', 'Kick-off call'));
    $this->actingAs($this->stranger)->get("/client/meetings/{$meeting->id}")->assertNotFound();

    Notification::fake();
    $this->actingAs($this->manager)->put("/admin/meetings/{$meeting->id}", [
        'client_id' => $this->acme->id, 'project_id' => $this->project->id, 'title' => 'Kick-off call',
        'starts_at' => now()->addDays(3)->setTime(11, 0)->format('Y-m-d\TH:i'), 'duration_minutes' => 60, 'link' => 'https://meet.example.com/abc',
    ])->assertRedirect();
    Notification::assertSentTo($this->acme, MeetingNotice::class, fn ($notification) => $notification->what === 'changed');

    Notification::fake();
    $this->actingAs($this->manager)->post("/admin/meetings/{$meeting->id}/cancel", ['reason' => 'Moving to next week.'])->assertSessionHas('success');
    Notification::assertSentTo($this->acme, MeetingNotice::class, fn ($notification) => $notification->what === 'cancelled');
    expect($meeting->fresh()->status)->toBe('cancelled');
    $this->actingAs($this->manager)->post("/admin/meetings/{$meeting->id}/cancel")->assertSessionHas('error');
});

it('publishes minutes after the meeting, raises what the client agreed to do, and takes their acknowledgement', function () {
    ($this->schedule)();
    $meeting = Meeting::query()->sole();

    ($this->minutes)($meeting)->assertSessionHas('success');
    $this->actingAs($this->acme)->get("/client/meetings/{$meeting->id}")->assertInertia(fn ($page) => $page->where('minutes', null));
    ($this->minutes)($meeting, publish: true)->assertSessionHas('error', 'Minutes are published after the meeting has started.');

    $this->travel(3)->days();
    Notification::fake();
    ($this->minutes)($meeting, publish: true)->assertSessionHas('success');

    $version = MeetingMinutes::query()->sole();
    $action = WaitingItem::query()->sole();
    expect($version->published_at)->not->toBeNull()
        ->and($meeting->fresh()->status)->toBe('held')
        ->and($action->kind)->toBe('action')
        ->and($action->meeting_id)->toBe($meeting->id)
        ->and($action->title)->toBe('Send the floor plans')
        ->and($action->blocks_work)->toBeFalse();
    Notification::assertSentTo([$this->acme, $this->technical], MinutesPublished::class);

    $kinds = collect($this->actingAs($this->technical)->get('/client')->viewData('page')['props']['waitingOnYou'])->pluck('kind');
    expect($kinds)->toContain('minutes')->toContain('waiting');

    $this->actingAs($this->technical)->get("/client/meetings/{$meeting->id}")
        ->assertInertia(fn ($page) => $page->where('canAnswer', true)->where('minutes.version', 1)->where('minutes.teamActions.0.owner', 'Ketan'));
    $this->actingAs($this->technical)->post("/client/meetings/{$meeting->id}/acknowledge")->assertSessionHas('success');

    expect($version->fresh()->acknowledged_by)->toBe($this->technical->id);
    Notification::assertSentTo($this->manager, MinutesAnswered::class);
    $this->actingAs($this->acme)->post("/client/meetings/{$meeting->id}/acknowledge")->assertSessionHas('error');

    // Published minutes never change.
    expect(fn () => $version->fresh()->update(['body' => 'Rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $version->fresh()->delete())->toThrow(LogicException::class);
});

it('takes a correction as the next version, raises only new actions, and keeps the earlier one readable', function () {
    ($this->schedule)(['starts_at' => now()->subHour()->format('Y-m-d\TH:i')]);
    $meeting = Meeting::query()->sole();
    ($this->minutes)($meeting, publish: true);

    $this->actingAs($this->acme)->post("/client/meetings/{$meeting->id}/dispute", ['note' => 'short'])->assertSessionHasErrors('note');
    $this->actingAs($this->acme)->post("/client/meetings/{$meeting->id}/dispute", ['note' => 'We agreed despatch goes first, not picking.'])->assertSessionHas('success');
    Notification::assertSentTo($this->manager, MinutesAnswered::class, fn ($notification) => $notification->minutes->disputed_at !== null);

    $this->actingAs($this->manager)->get("/admin/meetings/{$meeting->id}")
        ->assertInertia(fn ($page) => $page->where('draft.exists', false)->where('draft.version', 2)->where('draft.body', "## Decided\n\n- Picking goes first."));

    ($this->minutes)($meeting, [
        'body' => "## Decided\n\n- Despatch goes first.",
        'client_actions' => [
            ['title' => 'Send the floor plans', 'due_on' => today()->addDays(3)->toDateString()],
            ['title' => 'Name a despatch lead', 'due_on' => null],
        ],
    ], publish: true)->assertSessionHas('success');

    expect(WaitingItem::query()->pluck('title')->all())->toBe(['Send the floor plans', 'Name a despatch lead'])
        ->and(MeetingMinutes::query()->where('version', 1)->sole()->body)->toBe("## Decided\n\n- Picking goes first.");

    $this->actingAs($this->acme)->get("/client/meetings/{$meeting->id}")
        ->assertInertia(fn ($page) => $page->where('minutes.version', 2)->where('canAnswer', true)->has('earlier', 1)
            ->where('earlier.0.disputeNote', 'We agreed despatch goes first, not picking.'));
    $this->actingAs($this->acme)->post("/client/meetings/{$meeting->id}/acknowledge")->assertSessionHas('success');
    expect(MeetingMinutes::query()->where('version', 2)->sole()->acknowledged_at)->not->toBeNull();
});

it('keeps client actions to meetings about a project', function () {
    ($this->schedule)(['project_id' => null, 'starts_at' => now()->subHour()->format('Y-m-d\TH:i')]);
    $meeting = Meeting::query()->sole();

    ($this->minutes)($meeting)->assertSessionHas('error');
    ($this->minutes)($meeting, ['client_actions' => []], publish: true)->assertSessionHas('success');
    expect(WaitingItem::query()->count())->toBe(0)
        ->and(MeetingMinutes::query()->sole()->published_at)->not->toBeNull();
});

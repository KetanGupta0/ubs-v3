<?php

use App\Models\AuditLog;
use App\Models\ClientMember;
use App\Models\MilestoneSignoff;
use App\Models\PaymentRequest;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\MilestoneAnswered;
use App\Notifications\MilestoneSignoffRequested;
use App\Notifications\PaymentRequested;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');
    Setting::put('invoicing.default_tax_rate', 18, 'invoicing');

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
    $this->stranger = User::factory()->client()->create();

    $this->project = Project::query()->create([
        'client_id' => $this->acme->id, 'name' => 'Warehouse system', 'status' => 'in_progress', 'manager_id' => $this->manager->id,
    ]);
    $this->milestone = $this->project->milestones()->create([
        'title' => 'Picking module', 'payment_amount' => 1_20_000_00, 'progress_percent' => 90, 'sort_order' => 1,
    ]);

    $this->base = "/client/projects/{$this->project->id}/milestones/{$this->milestone->id}";
    $this->ask = fn (array $deliverables = ['Picking screens', 'Barcode scanning'], ?User $by = null) => $this->actingAs($by ?? $this->manager)
        ->post("/admin/projects/{$this->project->id}/milestones/{$this->milestone->id}/signoff", ['deliverables' => $deliverables, 'note' => 'On staging now.']);
});

it('asks the signatories, lets them accept, and raises the milestone\'s invoice once', function () {
    ($this->ask)()->assertSessionHas('success');

    Notification::assertSentTo([$this->acme, $this->signatory], MilestoneSignoffRequested::class);
    Notification::assertNotSentTo($this->finance, MilestoneSignoffRequested::class);

    // Finance can read it, but accepting is for a signatory or an owner.
    $this->actingAs($this->finance)->get($this->base)->assertOk()
        ->assertInertia(fn ($page) => $page->component('client/projects/Milestone')->where('canAnswer', false)->where('pending.deliverables', ['Picking screens', 'Barcode scanning']));
    $this->actingAs($this->finance)->post("{$this->base}/accept", ['confirm' => true])->assertForbidden();

    $this->actingAs($this->signatory)->get($this->base)->assertInertia(fn ($page) => $page->where('canAnswer', true));
    $this->actingAs($this->signatory)->post("{$this->base}/accept", ['confirm' => false])->assertSessionHasErrors('confirm');
    $this->actingAs($this->signatory)->post("{$this->base}/accept", ['confirm' => true, 'comments' => 'Looks good.'])->assertSessionHas('success');

    $milestone = $this->milestone->fresh();
    $signoff = MilestoneSignoff::query()->sole();
    $payment = PaymentRequest::query()->sole();

    expect($milestone->accepted_at)->not->toBeNull()
        ->and($milestone->completed_at)->not->toBeNull()
        ->and($milestone->progress_percent)->toBe(100)
        ->and($signoff->decision)->toBe('accepted')
        ->and($signoff->decided_by)->toBe($this->signatory->id)
        ->and($signoff->comments)->toBe('Looks good.')
        ->and($signoff->payment_request_id)->toBe($payment->id)
        ->and($payment->user_id)->toBe($this->acme->id)
        ->and($payment->payable_type)->toBe(ProjectMilestone::class)
        ->and($payment->payable_id)->toBe($milestone->id)
        ->and($payment->subtotal)->toBe(1_20_000_00)
        ->and($payment->total)->toBe(1_41_600_00)
        ->and($payment->invoice)->not->toBeNull()
        ->and($payment->due_on->toDateString())->toBe(today()->addDays(7)->toDateString());

    Notification::assertSentTo([$this->acme, $this->finance], PaymentRequested::class);
    Notification::assertSentTo([$this->owner, $this->manager], MilestoneAnswered::class);
    expect(AuditLog::query()->whereIn('action', ['milestone.signoff_requested', 'milestone.accepted', 'payment.requested'])->count())->toBe(3);

    // Answered is answered.
    $this->actingAs($this->acme)->post("{$this->base}/accept", ['confirm' => true])
        ->assertSessionHas('error', 'There is nothing waiting for your answer on this milestone.');
    ($this->ask)()->assertSessionHas('error', 'The client has already accepted this milestone.');
    expect(PaymentRequest::query()->count())->toBe(1);
});

it('takes changes with a reason, then a fresh round, and keeps both', function () {
    ($this->ask)();

    $this->actingAs($this->signatory)->post("{$this->base}/reject", ['comments' => 'short'])->assertSessionHasErrors('comments');
    $this->actingAs($this->signatory)->post("{$this->base}/reject", ['comments' => 'Scanning fails on the older handhelds.'])->assertSessionHas('success');

    expect($this->milestone->fresh()->accepted_at)->toBeNull()
        ->and(PaymentRequest::query()->count())->toBe(0);
    Notification::assertSentTo($this->manager, MilestoneAnswered::class, fn ($notification) => $notification->signoff->decision === 'rejected');

    $this->actingAs($this->manager)->get("/admin/projects/{$this->project->id}")
        ->assertInertia(fn ($page) => $page->where('milestones.0.signoff', 'changes')->where('milestones.0.lastDeliverables', ['Picking screens', 'Barcode scanning']));

    ($this->ask)(['Picking screens', 'Barcode scanning, older handhelds included'])->assertSessionHas('success');
    $this->actingAs($this->acme)->post("{$this->base}/accept", ['confirm' => true])->assertSessionHas('success');

    $this->actingAs($this->signatory)->get($this->base)
        ->assertInertia(fn ($page) => $page->where('pending', null)->has('history', 2)
            ->where('history.0.state', 'accepted')
            ->where('history.1.state', 'changes')
            ->where('history.1.comments', 'Scanning fails on the older handhelds.'));
});

it('raises no second invoice for a milestone already billed, and none for one without money', function () {
    $this->actingAs($this->owner)->post('/admin/billing', [
        'user_id' => $this->acme->id, 'title' => 'Picking module', 'amount' => 120000, 'tax_rate' => 18,
        'project_id' => $this->project->id, 'milestone_id' => $this->milestone->id,
    ])->assertRedirect();

    $billed = PaymentRequest::query()->sole();

    ($this->ask)();
    $this->actingAs($this->signatory)->post("{$this->base}/accept", ['confirm' => true])
        ->assertSessionHas('success', "Accepted. Its invoice {$billed->invoice->number} was raised earlier and is under Payments.");
    expect(PaymentRequest::query()->count())->toBe(1);
    $this->actingAs($this->finance)->get($this->base)->assertInertia(fn ($page) => $page->where('invoice.id', $billed->id));

    $free = $this->project->milestones()->create(['title' => 'Kick-off', 'sort_order' => 0]);
    $this->actingAs($this->manager)->post("/admin/projects/{$this->project->id}/milestones/{$free->id}/signoff", ['deliverables' => ['Plan agreed']]);
    $this->actingAs($this->signatory)->post("/client/projects/{$this->project->id}/milestones/{$free->id}/accept", ['confirm' => true])->assertSessionHas('success', 'Accepted. Thank you.');
    expect(PaymentRequest::query()->count())->toBe(1)
        ->and($free->fresh()->accepted_at)->not->toBeNull();
});

it('keeps an accepted milestone as it was accepted', function () {
    ($this->ask)();
    $this->actingAs($this->signatory)->post("{$this->base}/accept", ['confirm' => true]);

    $admin = "/admin/projects/{$this->project->id}/milestones/{$this->milestone->id}";
    $this->actingAs($this->manager)->put($admin, ['title' => 'Something else', 'payment_amount' => 1])->assertSessionHas('error');
    $this->actingAs($this->manager)->post("{$admin}/complete")->assertSessionHas('error');
    $this->actingAs($this->manager)->delete($admin)->assertSessionHas('error');

    expect(fn () => $this->milestone->fresh()->update(['payment_amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $this->milestone->fresh()->delete())->toThrow(LogicException::class)
        ->and($this->milestone->fresh()->title)->toBe('Picking module');
});

it('lets staff withdraw a request, which can then not be answered, and needs a list to ask', function () {
    ($this->ask)([])->assertSessionHasErrors('deliverables');
    ($this->ask)();
    ($this->ask)()->assertSessionHas('error', 'This milestone is already waiting for the client. Withdraw that request first.');

    $this->actingAs($this->manager)->post("/admin/projects/{$this->project->id}/milestones/{$this->milestone->id}/signoff/withdraw")->assertSessionHas('success');
    $this->actingAs($this->signatory)->post("{$this->base}/accept", ['confirm' => true])
        ->assertSessionHas('error', 'There is nothing waiting for your answer on this milestone.');
    expect($this->milestone->fresh()->accepted_at)->toBeNull();

    // A milestone with nothing but a withdrawn request can still be removed.
    $this->actingAs($this->manager)->delete("/admin/projects/{$this->project->id}/milestones/{$this->milestone->id}")->assertSessionHas('success');
});

it('shows a milestone only to its own organisation', function () {
    ($this->ask)();

    $this->actingAs($this->stranger)->get($this->base)->assertNotFound();
    $this->actingAs($this->stranger)->post("{$this->base}/accept", ['confirm' => true])->assertNotFound();

    $theirs = Project::query()->create(['client_id' => $this->stranger->id, 'name' => 'Theirs', 'status' => 'in_progress']);
    $this->actingAs($this->signatory)->get("/client/projects/{$theirs->id}/milestones/{$this->milestone->id}")->assertNotFound();

    $this->actingAs($this->signatory)->get("/client/projects/{$this->project->id}")
        ->assertInertia(fn ($page) => $page->where('milestones.0.signoff', 'waiting'));
});

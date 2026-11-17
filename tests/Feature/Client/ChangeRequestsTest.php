<?php

use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\ClientMember;
use App\Models\DocumentSignature;
use App\Models\PaymentRequest;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ChangeRequestNotice;
use App\Notifications\DocumentSigned;
use App\Notifications\OneTimeCodeNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** The code in the last one time code email the person was sent. */
function changeSigningCode(User $user): string
{
    return (fn () => $this->code)->call(Notification::sent($user, OneTimeCodeNotification::class)->last());
}

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');
    Setting::put('invoicing.default_tax_rate', 18, 'invoicing');

    $this->owner = User::factory()->owner()->create();
    $this->manager = User::factory()->admin()->create();
    $this->manager->permissions()->attach(Permission::query()->whereIn('key', ['projects.view', 'projects.manage', 'proposals.manage'])->get());

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
        'budget' => 8_50_000_00, 'target_date' => '2027-01-10',
    ]);

    $this->ask = fn (User $who) => $this->actingAs($who)->post("/client/projects/{$this->project->id}/changes", [
        'title' => 'Returns in the driver app',
        'description' => 'Drivers should be able to log a return at the door.',
    ]);
    $this->quote = fn (ChangeRequest $change, array $overrides = []) => $this->actingAs($this->manager)->post("/admin/change-requests/{$change->id}/quote", [
        'amount' => 120000, 'days' => 10, 'estimate_note' => 'A returns screen and its sync.', ...$overrides,
    ]);
    $this->base = fn (ChangeRequest $change) => "/client/projects/{$this->project->id}/changes/{$change->id}";
});

it('takes a change from anybody in the organisation, and fixes it as an addendum when we quote', function () {
    ($this->ask)($this->technical)->assertRedirect();
    $change = ChangeRequest::query()->sole();

    expect($change->status)->toBe('requested')
        ->and($change->number)->toStartWith('UBS/CR/')
        ->and($change->raised_by)->toBe($this->technical->id);
    Notification::assertSentTo($this->manager, ChangeRequestNotice::class, fn ($notification) => $notification->what === 'raised');

    // A preview renders what is being typed, and keeps nothing.
    $preview = $this->actingAs($this->manager)->get("/admin/change-requests/{$change->id}/pdf?amount=5000&days=2")->assertOk();
    expect($preview->getContent())->toStartWith('%PDF')
        ->and($change->fresh()->amount)->toBeNull()
        ->and(Storage::disk('private')->allFiles())->toBe([]);

    ($this->quote)($change)->assertSessionHas('success');
    $change->refresh();

    expect($change->status)->toBe('quoted')
        ->and($change->amount)->toBe(1_20_000_00)
        ->and($change->days)->toBe(10)
        ->and($change->pdf_sha256)->toBe(hash('sha256', Storage::disk('private')->get($change->pdf_path)))
        ->and($change->parties['to']['name'])->toBe('Acme Logistics');
    Notification::assertSentTo([$this->acme, $this->signatory], ChangeRequestNotice::class, fn ($notification) => $notification->what === 'quoted');
    Notification::assertNotSentTo([$this->finance, $this->technical], ChangeRequestNotice::class, fn ($notification) => $notification->what === 'quoted');

    ($this->quote)($change)->assertSessionHas('error');
    expect(fn () => $change->fresh()->forceFill(['amount' => 1])->save())->toThrow(LogicException::class);

    $this->actingAs($this->technical)->get(($this->base)($change))->assertInertia(fn ($page) => $page->component('client/projects/Change')->where('canAnswer', false));
    $this->actingAs($this->signatory)->get(($this->base)($change))->assertInertia(fn ($page) => $page->where('canAnswer', true)->where('change.amount', '₹1,20,000.00'));
    $this->actingAs($this->stranger)->get(($this->base)($change))->assertNotFound();
});

it('approves by signing, and the project\'s value, dates and milestones follow', function () {
    ($this->ask)($this->signatory);
    $change = ChangeRequest::query()->sole();
    ($this->quote)($change);

    $this->actingAs($this->finance)->postJson(($this->base)($change).'/sign/code')->assertForbidden();
    $this->actingAs($this->signatory)->postJson(($this->base)($change).'/sign/code')->assertOk();
    $this->actingAs($this->signatory)->post(($this->base)($change).'/sign', [
        'name' => 'Asha Rao', 'code' => changeSigningCode($this->signatory), 'confirm' => true,
    ])->assertSessionHas('success');

    $change->refresh();
    $project = $this->project->fresh();
    $milestone = $change->milestone;

    expect($change->status)->toBe('approved')
        ->and(DocumentSignature::query()->sole()->document_sha256)->toBe($change->pdf_sha256)
        ->and($project->budget)->toBe(8_50_000_00 + 1_20_000_00)
        ->and($project->target_date->toDateString())->toBe('2027-01-20')
        ->and($milestone->title)->toBe("Change {$change->number}: Returns in the driver app")
        ->and($milestone->payment_amount)->toBe(1_20_000_00)
        ->and($project->updates()->where('visible_to_client', true)->where('title', "Change {$change->number} approved")->exists())->toBeTrue()
        ->and(AuditLog::query()->whereIn('action', ['change_request.approved', 'project.changed_by_request'])->count())->toBe(2)
        ->and(pdfPagesOf(Storage::disk('private')->get($change->signed_pdf_path)))->toBe(pdfPagesOf(Storage::disk('private')->get($change->pdf_path)) + 1);
    Notification::assertSentTo([$this->owner, $this->manager], DocumentSigned::class);

    // The work is billed like any milestone: when the client accepts it.
    expect(PaymentRequest::query()->count())->toBe(0);
    $this->actingAs($this->manager)->post("/admin/projects/{$project->id}/milestones/{$milestone->id}/signoff", ['deliverables' => ['Returns screen']]);
    $this->actingAs($this->signatory)->post("/client/projects/{$project->id}/milestones/{$milestone->id}/accept", ['confirm' => true]);
    expect(PaymentRequest::query()->sole()->subtotal)->toBe(1_20_000_00);

    // Approved is approved.
    $this->actingAs($this->signatory)->postJson(($this->base)($change).'/sign/code')
        ->assertStatus(422)
        ->assertJsonPath('message', 'This change request is not waiting for approval.');
});

it('takes a decline with a reason, and a fresh estimate is a new request', function () {
    ($this->ask)($this->signatory);
    $change = ChangeRequest::query()->sole();
    ($this->quote)($change);

    $this->actingAs($this->finance)->post(($this->base)($change).'/decline', ['reason' => 'Too dear'])->assertForbidden();
    $this->actingAs($this->signatory)->post(($this->base)($change).'/decline', ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->signatory)->post(($this->base)($change).'/decline', ['reason' => 'More than we planned for this quarter.'])->assertSessionHas('success');

    expect($change->fresh()->status)->toBe('declined')
        ->and($this->project->fresh()->budget)->toBe(8_50_000_00);
    Notification::assertSentTo($this->manager, ChangeRequestNotice::class, fn ($notification) => $notification->what === 'declined');

    $this->actingAs($this->manager)->post("/admin/change-requests/{$change->id}/again")->assertRedirect();
    $next = ChangeRequest::query()->where('previous_id', $change->id)->sole();
    expect($next->status)->toBe('requested')
        ->and($next->title)->toBe($change->title)
        ->and($next->number)->not->toBe($change->number);

    ($this->quote)($next, ['amount' => 80000, 'days' => 5]);
    $this->actingAs($this->manager)->post("/admin/change-requests/{$next->id}/withdraw")->assertSessionHas('success');
    $this->actingAs($this->signatory)->postJson(($this->base)($next).'/sign/code')->assertStatus(422);
});

function pdfPagesOf(string $pdf): int
{
    return preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', $pdf);
}

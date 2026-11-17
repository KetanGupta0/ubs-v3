<?php

use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\ClientMember;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AgreementSent;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    Setting::put('company.name', 'Unboundbyte', 'company');
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');
    Setting::put('company.state', 'Madhya Pradesh', 'company');

    $this->owner = User::factory()->owner()->create();
    $this->acme = User::factory()->client()->create(['name' => 'Priya Acme']);
    $this->acme->profile()->updateOrCreate([], ['company' => 'Acme Logistics', 'city' => 'Indore']);
    $this->signatory = User::factory()->client()->create();
    ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $this->signatory->id, 'role' => 'signatory', 'joined_at' => now()]);

    $this->project = Project::query()->create(['client_id' => $this->acme->id, 'name' => 'Warehouse system', 'status' => 'in_progress', 'progress_percent' => 40]);
    $this->stranger = User::factory()->client()->create();
    $this->theirProject = Project::query()->create(['client_id' => $this->stranger->id, 'name' => 'Not Acme', 'status' => 'in_progress']);

    $this->draft = fn (array $overrides = []) => [
        'client_id' => $this->acme->id,
        'kind' => 'sow',
        'title' => 'Statement of work: warehouse system',
        'body' => "## Scope\n\n1. Picking\n2. Despatch\n\n## Fees\n\nAs per the quotation.",
        'project_id' => $this->project->id,
        'effective_on' => today()->toDateString(),
        'expires_on' => today()->addYear()->toDateString(),
        ...$overrides,
    ];
});

/* ------------------------------------------------------------ agreements */

it('writes a draft for an organisation, with links that must be its own', function () {
    $this->actingAs($this->owner)->post('/admin/agreements', ($this->draft)(['project_id' => $this->theirProject->id]))
        ->assertSessionHasErrors('project_id');
    $this->actingAs($this->owner)->post('/admin/agreements', ($this->draft)(['client_id' => $this->signatory->id]))
        ->assertSessionHasErrors('client_id');

    $this->actingAs($this->owner)->post('/admin/agreements', ($this->draft)())->assertRedirect();

    $agreement = Agreement::query()->sole();
    expect($agreement->status)->toBe('draft')
        ->and($agreement->number)->toStartWith('UBS/A/')
        ->and($agreement->pdf_path)->toBeNull();

    // A draft previews as a PDF and is never kept.
    $preview = $this->actingAs($this->owner)->get("/admin/agreements/{$agreement->id}/pdf")->assertOk();
    expect($preview->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($preview->getContent())->toStartWith('%PDF')
        ->and(Storage::disk('private')->allFiles())->toBe([]);

    // Clients never see drafts.
    $this->actingAs($this->acme)->get("/client/agreements/{$agreement->id}")->assertNotFound();
    $this->actingAs($this->owner)->get('/admin/agreements')->assertOk()->assertInertia(fn ($page) => $page->has('table.rows', 1));
});

it('freezes the text, the parties and the PDF with its hash when it is sent', function () {
    $this->actingAs($this->owner)->post('/admin/agreements', ($this->draft)());
    $agreement = Agreement::query()->sole();

    $this->actingAs($this->owner)->post("/admin/agreements/{$agreement->id}/send")->assertSessionHas('success');

    $agreement->refresh();
    $stored = Storage::disk('private')->get($agreement->pdf_path);

    expect($agreement->status)->toBe('sent')
        ->and($agreement->sent_at)->not->toBeNull()
        ->and($agreement->pdf_sha256)->toBe(hash('sha256', $stored))
        ->and($agreement->parties['to']['name'])->toBe('Acme Logistics')
        ->and($agreement->parties['from']['name'])->toBe('Unboundbyte Solutions Private Limited');

    Notification::assertSentTo([$this->signatory, $this->acme], AgreementSent::class);
    expect(AuditLog::query()->where('action', 'agreement.sent')->exists())->toBeTrue();

    // A later change to the client's details does not rewrite what was sent.
    $this->acme->profile->update(['company' => 'Acme Global']);
    $download = $this->actingAs($this->signatory)->get("/client/agreements/{$agreement->id}/pdf")->assertOk();
    expect(hash('sha256', $download->getContent()))->toBe($agreement->pdf_sha256);

    // Nothing can change its text now, by any route.
    $this->actingAs($this->owner)->put("/admin/agreements/{$agreement->id}", ($this->draft)(['title' => 'Changed']))->assertStatus(422);
    expect(fn () => $agreement->forceFill(['body' => 'Rewritten'])->save())->toThrow(LogicException::class);
    expect(fn () => $agreement->delete())->toThrow(LogicException::class);
    expect($agreement->fresh()->title)->toBe('Statement of work: warehouse system');
});

it('changes a sent agreement only as a new version, which supersedes it when sent', function () {
    $this->actingAs($this->owner)->post('/admin/agreements', ($this->draft)());
    $first = Agreement::query()->sole();
    $this->actingAs($this->owner)->post("/admin/agreements/{$first->id}/send");

    $this->actingAs($this->owner)->post("/admin/agreements/{$first->id}/revise")->assertRedirect();
    $second = Agreement::query()->where('previous_id', $first->id)->sole();

    expect($second->version)->toBe(2)
        ->and($second->status)->toBe('draft')
        ->and($second->pdf_path)->toBeNull()
        ->and($first->fresh()->status)->toBe('sent');

    $this->actingAs($this->owner)->put("/admin/agreements/{$second->id}", ($this->draft)(['title' => 'Statement of work: phase two']));
    $this->actingAs($this->owner)->post("/admin/agreements/{$second->id}/send");

    expect($first->fresh()->status)->toBe('superseded')
        ->and($second->fresh()->status)->toBe('sent');

    $this->actingAs($this->acme)->get("/client/agreements/{$first->id}")
        ->assertInertia(fn ($page) => $page->where('agreement.status', 'superseded')->where('agreement.replacedBy', $second->id));
});

it('shows an organisation its agreements and nobody else\'s, and withdraws only what is waiting', function () {
    $this->actingAs($this->owner)->post('/admin/agreements', ($this->draft)());
    $agreement = Agreement::query()->sole();
    $this->actingAs($this->owner)->post("/admin/agreements/{$agreement->id}/send");

    $this->actingAs($this->signatory)->get('/client/agreements')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('client/agreements/Index')->has('agreements', 1));
    $this->actingAs($this->stranger)->get("/client/agreements/{$agreement->id}")->assertNotFound();
    $this->actingAs($this->stranger)->get("/client/agreements/{$agreement->id}/pdf")->assertNotFound();

    $this->actingAs($this->owner)->post("/admin/agreements/{$agreement->id}/withdraw")->assertSessionHas('success');
    expect($agreement->fresh()->status)->toBe('withdrawn');
    $this->actingAs($this->owner)->post("/admin/agreements/{$agreement->id}/withdraw")->assertStatus(422);
});

/* ------------------------------------------------------- proposal PDFs */

it('freezes a proposal\'s PDF as it is sent, and a revision starts without it', function () {
    $proposal = Proposal::query()->create([
        'client_id' => $this->acme->id, 'title' => 'Driver app', 'status' => 'draft', 'valid_until' => today()->addWeek(), 'body' => 'What we will build.',
    ]);

    $this->actingAs($this->owner)->get("/admin/proposals/{$proposal->id}/pdf")->assertOk();
    expect($proposal->fresh()->pdf_path)->toBeNull();

    $this->actingAs($this->owner)->post("/admin/proposals/{$proposal->id}/send")->assertRedirect();
    $proposal->refresh();

    expect($proposal->pdf_path)->not->toBeNull()
        ->and($proposal->pdf_sha256)->toBe(hash('sha256', Storage::disk('private')->get($proposal->pdf_path)))
        ->and($proposal->parties['to']['name'])->toBe('Acme Logistics');

    $download = $this->actingAs($this->signatory)->get("/client/proposals/{$proposal->id}/pdf")->assertOk();
    expect(hash('sha256', $download->getContent()))->toBe($proposal->pdf_sha256);

    $this->actingAs($this->owner)->post("/admin/proposals/{$proposal->id}/revise");
    expect(Proposal::query()->where('version', 2)->sole()->pdf_path)->toBeNull();
});

it('keeps the PDF of a proposal sent before PDFs were kept, the first time it is asked for', function () {
    $proposal = Proposal::query()->create([
        'client_id' => $this->acme->id, 'title' => 'Older one', 'status' => 'sent', 'sent_at' => now()->subMonth(), 'valid_until' => today()->addWeek(),
    ]);

    $first = $this->actingAs($this->acme)->get("/client/proposals/{$proposal->id}/pdf")->assertOk()->getContent();
    $second = $this->actingAs($this->acme)->get("/client/proposals/{$proposal->id}/pdf")->assertOk()->getContent();

    expect($first)->toBe($second)->and($proposal->fresh()->pdf_sha256)->toBe(hash('sha256', $first));
});

/* --------------------------------------------------------- status report */

it('prints where a project stands, for its organisation and staff only', function () {
    $this->project->milestones()->create(['title' => 'Picking', 'progress_percent' => 80, 'sort_order' => 1]);

    $response = $this->actingAs($this->signatory)->get("/client/projects/{$this->project->id}/status-report")->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->getContent())->toStartWith('%PDF');

    $this->actingAs($this->stranger)->get("/client/projects/{$this->project->id}/status-report")->assertNotFound();
    $this->actingAs($this->owner)->get("/admin/projects/{$this->project->id}/status-report")->assertOk();
});

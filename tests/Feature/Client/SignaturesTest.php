<?php

use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\ClientMember;
use App\Models\DocumentSignature;
use App\Models\Permission;
use App\Models\Proposal;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DocumentCountersigned;
use App\Notifications\DocumentSigned;
use App\Notifications\OneTimeCodeNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/** The code in the last one time code email the person was sent. */
function emailedCode(User $user): string
{
    $sent = Notification::sent($user, OneTimeCodeNotification::class);
    expect($sent)->not->toBeEmpty();

    return (fn () => $this->code)->call($sent->last());
}

function signingStaff(array $keys): User
{
    $staff = User::factory()->admin()->create();
    $staff->permissions()->attach(Permission::query()->whereIn('key', $keys)->get());

    return $staff->fresh();
}

function pageCount(string $pdf): int
{
    return preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', $pdf);
}

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    Setting::put('company.name', 'Unboundbyte', 'company');
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');

    $this->owner = User::factory()->owner()->create();
    $this->acme = User::factory()->client()->create(['name' => 'Priya Acme']);
    $this->acme->profile()->updateOrCreate([], ['company' => 'Acme Logistics', 'city' => 'Indore']);

    $member = function (string $role) {
        $user = User::factory()->client()->create();
        ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    };
    $this->signatory = $member('signatory');
    $this->finance = $member('finance');
    $this->stranger = User::factory()->client()->create();

    $this->actingAs($this->owner)->post('/admin/agreements', [
        'client_id' => $this->acme->id,
        'kind' => 'msa',
        'title' => 'Master services agreement',
        'body' => "## Services\n\nWhat we do for each other.",
        'effective_on' => today()->toDateString(),
    ]);
    $this->agreement = Agreement::query()->sole();
    $this->actingAs($this->owner)->post("/admin/agreements/{$this->agreement->id}/send");
    $this->agreement->refresh();

    $this->signs = fn (User $who, array $overrides = []) => $this->actingAs($who)->post("/client/agreements/{$this->agreement->id}/sign", [
        'name' => 'Asha Rao',
        'designation' => 'Director',
        'code' => emailedCode($who),
        'confirm' => true,
        ...$overrides,
    ]);
});

it('lets a signatory sign with a code sent to their own address, then lets us countersign', function () {
    $this->actingAs($this->signatory)->postJson("/client/agreements/{$this->agreement->id}/sign/code")
        ->assertOk()
        ->assertJsonPath('sha256', $this->agreement->pdf_sha256)
        ->assertJsonPath('sentTo', mb_substr($this->signatory->email, 0, 2).str_repeat('•', mb_strlen(strstr($this->signatory->email, '@', true)) - 2).strstr($this->signatory->email, '@'));

    ($this->signs)($this->signatory)->assertSessionHas('success');

    $this->agreement->refresh();
    $signature = DocumentSignature::query()->sole();

    expect($this->agreement->status)->toBe('signed')
        ->and($signature->party)->toBe('client')
        ->and($signature->user_id)->toBe($this->signatory->id)
        ->and($signature->signer_name)->toBe('Asha Rao')
        ->and($signature->signer_designation)->toBe('Director')
        ->and($signature->organisation)->toBe('Acme Logistics')
        ->and($signature->document_sha256)->toBe($this->agreement->pdf_sha256)
        ->and($signature->ip)->not->toBeNull();

    Notification::assertSentTo($this->owner, DocumentSigned::class);
    expect(AuditLog::query()->where('action', 'agreement.signed')->exists())->toBeTrue();

    // The signed copy is the file as sent, plus a certificate of signing.
    $original = Storage::disk('private')->get($this->agreement->pdf_path);
    $signed = $this->actingAs($this->finance)->get("/client/agreements/{$this->agreement->id}/signed")->assertOk()->getContent();
    expect($signed)->toStartWith('%PDF')
        ->and(pageCount($signed))->toBe(pageCount($original) + 1)
        ->and(hash('sha256', $original))->toBe($this->agreement->pdf_sha256);

    // Somebody on our side countersigns the same way, and needs the permission to.
    $sender = signingStaff(['proposals.manage']);
    $this->actingAs($sender)->postJson("/admin/agreements/{$this->agreement->id}/countersign/code")->assertForbidden();
    $this->actingAs($sender)->get("/admin/agreements/{$this->agreement->id}")
        ->assertInertia(fn ($page) => $page->where('canCountersign', false));

    $director = signingStaff(['proposals.manage', 'agreements.countersign']);
    $this->actingAs($director)->get("/admin/agreements/{$this->agreement->id}")
        ->assertInertia(fn ($page) => $page->where('canCountersign', true)->has('agreement.signatures', 1));
    $this->actingAs($director)->postJson("/admin/agreements/{$this->agreement->id}/countersign/code")->assertOk();
    $this->actingAs($director)->post("/admin/agreements/{$this->agreement->id}/countersign", [
        'name' => 'Ketan Director', 'code' => emailedCode($director), 'confirm' => true,
    ])->assertSessionHas('success');

    expect($this->agreement->fresh()->status)->toBe('countersigned')
        ->and(DocumentSignature::query()->where('party', 'us')->sole()->organisation)->toBe('Unboundbyte Solutions Private Limited');
    Notification::assertSentTo([$this->acme, $this->signatory], DocumentCountersigned::class);
    Notification::assertNotSentTo($this->finance, DocumentCountersigned::class);

    $this->actingAs($this->signatory)->get("/client/agreements/{$this->agreement->id}")
        ->assertInertia(fn ($page) => $page->where('agreement.status', 'countersigned')->has('agreement.signatures', 2)->where('canSign', false));
    expect(pageCount(Storage::disk('private')->get($this->agreement->fresh()->signed_pdf_path)))->toBe(pageCount($original) + 1);
});

it('keeps signing to signatories and owners, and to the organisation it was sent to', function () {
    $this->actingAs($this->finance)->postJson("/client/agreements/{$this->agreement->id}/sign/code")
        ->assertForbidden()
        ->assertJsonPath('message', 'Only an authorised signatory or an owner in your organisation can do this.');
    $this->actingAs($this->finance)->get("/client/agreements/{$this->agreement->id}")
        ->assertInertia(fn ($page) => $page->where('canSign', false));

    $this->actingAs($this->stranger)->postJson("/client/agreements/{$this->agreement->id}/sign/code")->assertNotFound();
    $this->actingAs($this->stranger)->get("/client/agreements/{$this->agreement->id}/signed")->assertNotFound();

    // The account holder is the organisation's owner and may sign.
    $this->actingAs($this->acme)->postJson("/client/agreements/{$this->agreement->id}/sign/code")->assertOk();
    $this->actingAs($this->acme)->get("/client/agreements/{$this->agreement->id}")
        ->assertInertia(fn ($page) => $page->where('canSign', true));

    // Nothing is signed yet, so there is no signed copy and nothing to countersign.
    $this->actingAs($this->acme)->get("/client/agreements/{$this->agreement->id}/signed")->assertNotFound();
    $this->actingAs($this->owner)->postJson("/admin/agreements/{$this->agreement->id}/countersign/code")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Only an agreement the client has signed can be countersigned.');
});

it('refuses a wrong, expired or reused code, and a signature without its name or consent', function () {
    $this->actingAs($this->signatory)->postJson("/client/agreements/{$this->agreement->id}/sign/code")->assertOk();
    $code = emailedCode($this->signatory);

    ($this->signs)($this->signatory, ['code' => $code === '000000' ? '111111' : '000000'])
        ->assertSessionHasErrors(['code' => 'That code is not right. Check it and try again.']);
    ($this->signs)($this->signatory, ['confirm' => false])->assertSessionHasErrors('confirm');
    ($this->signs)($this->signatory, ['name' => ' '])->assertSessionHasErrors('name');

    // A second request within the minute is refused, so the code stands.
    $this->actingAs($this->signatory)->postJson("/client/agreements/{$this->agreement->id}/sign/code")
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($message) => str_starts_with($message, 'A code was sent a moment ago.'));

    $this->travel(11)->minutes();
    ($this->signs)($this->signatory, ['code' => $code])->assertSessionHasErrors(['code' => 'That code has expired. Ask for a new one.']);

    expect(DocumentSignature::query()->count())->toBe(0)
        ->and($this->agreement->fresh()->status)->toBe('sent');

    // A fresh code works once.
    $this->actingAs($this->signatory)->postJson("/client/agreements/{$this->agreement->id}/sign/code")->assertOk();
    $fresh = emailedCode($this->signatory);
    ($this->signs)($this->signatory, ['code' => $fresh])->assertSessionHas('success');
    ($this->signs)($this->signatory, ['code' => $fresh])->assertSessionHasErrors(['code' => 'This agreement is not waiting for your signature.']);
    expect(DocumentSignature::query()->count())->toBe(1);
});

it('refuses to sign a PDF that is no longer the one that was sent', function () {
    Storage::disk('private')->put($this->agreement->pdf_path, '%PDF-1.4 something else');

    $this->actingAs($this->signatory)->postJson("/client/agreements/{$this->agreement->id}/sign/code")->assertOk();
    ($this->signs)($this->signatory)
        ->assertSessionHasErrors(['code' => 'This document cannot be signed right now. We have been told and will look at it.']);

    expect(DocumentSignature::query()->count())->toBe(0)
        ->and($this->agreement->fresh()->status)->toBe('sent');
});

it('never changes or deletes a signature, and keeps a signed agreement\'s text', function () {
    $this->actingAs($this->signatory)->postJson("/client/agreements/{$this->agreement->id}/sign/code");
    ($this->signs)($this->signatory);

    $signature = DocumentSignature::query()->sole();
    expect(fn () => $signature->update(['signer_name' => 'Somebody else']))->toThrow(LogicException::class)
        ->and(fn () => $signature->delete())->toThrow(LogicException::class)
        ->and(fn () => $this->agreement->fresh()->forceFill(['body' => 'Rewritten'])->save())->toThrow(LogicException::class)
        ->and(DocumentSignature::query()->sole()->signer_name)->toBe('Asha Rao');
});

it('records the first time the client opens it, never staff and never "view as"', function () {
    $this->actingAs($this->owner)->get("/admin/agreements/{$this->agreement->id}")->assertOk();
    $this->actingAs($this->owner)->get("/admin/agreements/{$this->agreement->id}/pdf")->assertOk();
    expect($this->agreement->fresh()->viewed_at)->toBeNull();

    $this->actingAs($this->owner)->post("/admin/clients/{$this->signatory->id}/view-as", ['reason' => 'They cannot find the agreement'])->assertRedirect();
    $this->get("/client/agreements/{$this->agreement->id}")->assertOk();
    expect($this->agreement->fresh()->viewed_at)->toBeNull();
    $this->post('/viewing-as/stop');

    $this->actingAs($this->finance)->get("/client/agreements/{$this->agreement->id}/pdf")->assertOk();
    $this->travel(1)->hour();
    $this->actingAs($this->signatory)->get("/client/agreements/{$this->agreement->id}")->assertOk();

    $agreement = $this->agreement->fresh();
    expect($agreement->viewed_by)->toBe($this->finance->id)
        ->and($agreement->viewed_at->lessThan(now()->subMinutes(30)))->toBeTrue();
    $this->actingAs($this->owner)->get("/admin/agreements/{$this->agreement->id}")
        ->assertInertia(fn ($page) => $page->where('agreement.viewedBy', $this->finance->name));
});

/* ------------------------------------------------------------ proposals */

it('accepts a proposal by signing it, with a note, and keeps the signed copy', function () {
    $proposal = Proposal::query()->create([
        'client_id' => $this->acme->id, 'title' => 'Driver app', 'status' => 'draft', 'valid_until' => today()->addWeek(), 'body' => 'What we will build.',
    ]);
    $this->actingAs($this->owner)->post("/admin/proposals/{$proposal->id}/send");
    $proposal->refresh();

    $this->actingAs($this->finance)->postJson("/client/proposals/{$proposal->id}/sign/code")->assertForbidden();
    $this->actingAs($this->signatory)->postJson("/client/proposals/{$proposal->id}/sign/code")->assertOk()->assertJsonPath('sha256', $proposal->pdf_sha256);
    $this->actingAs($this->signatory)->post("/client/proposals/{$proposal->id}/sign", [
        'name' => 'Asha Rao', 'code' => emailedCode($this->signatory), 'note' => 'Start in December.', 'confirm' => true,
    ])->assertSessionHas('success');

    $proposal->refresh();
    expect($proposal->status)->toBe('accepted')
        ->and($proposal->response_note)->toBe('Start in December.')
        ->and($proposal->responded_ip)->not->toBeNull()
        ->and($proposal->signatures()->sole()->document_sha256)->toBe($proposal->pdf_sha256)
        ->and(AuditLog::query()->where('action', 'proposal.accepted')->exists())->toBeTrue();
    Notification::assertSentTo($this->owner, DocumentSigned::class);

    $signed = $this->actingAs($this->owner)->get("/admin/proposals/{$proposal->id}/signed")->assertOk()->getContent();
    expect(pageCount($signed))->toBe(pageCount(Storage::disk('private')->get($proposal->pdf_path)) + 1);
    $this->actingAs($this->stranger)->get("/client/proposals/{$proposal->id}/signed")->assertNotFound();

    // Answered is answered.
    $this->travel(2)->minutes();
    $this->actingAs($this->signatory)->postJson("/client/proposals/{$proposal->id}/sign/code")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This proposal has already been answered.');
});

it('signs a proposal sent before PDFs were kept against the PDF frozen when the code is asked for', function () {
    $proposal = Proposal::query()->create([
        'client_id' => $this->acme->id, 'title' => 'Older one', 'status' => 'sent', 'sent_at' => now()->subDay(), 'valid_until' => today()->addWeek(),
    ]);

    $this->actingAs($this->signatory)->postJson("/client/proposals/{$proposal->id}/sign/code")->assertOk();
    $this->actingAs($this->signatory)->post("/client/proposals/{$proposal->id}/sign", [
        'name' => 'Asha Rao', 'code' => emailedCode($this->signatory), 'confirm' => true,
    ])->assertSessionHas('success');

    $proposal->refresh();
    expect($proposal->pdf_sha256)->toBe(hash('sha256', Storage::disk('private')->get($proposal->pdf_path)))
        ->and($proposal->signatures()->sole()->document_sha256)->toBe($proposal->pdf_sha256);
});

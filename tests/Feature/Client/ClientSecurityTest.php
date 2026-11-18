<?php

use App\Models\ClientMember;
use App\Models\MilestoneSignoff;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

/** Two-step sign in, set up the way a person sets it up. */
function turnOnTwoStep(User $user): void
{
    $service = app(TwoFactorService::class);
    $record = $service->beginSetup($user);
    $service->confirmSetup($user->fresh(), app(Google2FA::class)->getCurrentOtp($record->secret));

    // The test reuses this instance across requests, where production loads a fresh user.
    $user->unsetRelation('twoFactorSecret');
}

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');
    Setting::put('invoicing.default_tax_rate', 18, 'invoicing');
    // The suite runs with the rule off; this file is where it is tested.
    config(['company.clients.two_factor_to_commit' => true]);

    $this->owner = User::factory()->owner()->create();
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

    $this->project = Project::query()->create(['client_id' => $this->acme->id, 'name' => 'Warehouse system', 'status' => 'in_progress']);
    $this->milestone = $this->project->milestones()->create(['title' => 'Picking module', 'sort_order' => 1]);
    MilestoneSignoff::query()->create(['milestone_id' => $this->milestone->id, 'project_id' => $this->project->id, 'deliverables' => ['Screens']]);

    $this->actingAs($this->owner)->post('/admin/billing', ['user_id' => $this->acme->id, 'title' => 'Advance', 'amount' => 1000, 'tax_rate' => 18]);
    $this->payment = PaymentRequest::query()->sole();
});

it('refuses signing, accepting and paying until two-step sign in is on, and says why', function () {
    $message = 'Turn on two-step sign in under Security first. Signing and paying for your organisation need it.';
    $accept = "/client/projects/{$this->project->id}/milestones/{$this->milestone->id}/accept";

    $this->actingAs($this->signatory)->post($accept, ['confirm' => true])->assertForbidden();
    $this->actingAs($this->finance)->postJson("/client/payments/{$this->payment->id}/checkout")->assertForbidden()->assertJsonPath('message', $message);
    $this->actingAs($this->acme)->postJson("/client/payments/{$this->payment->id}/checkout")->assertForbidden();

    // The screens are told, so they can say so before anybody is refused.
    $this->actingAs($this->finance)->get('/client')->assertInertia(fn ($page) => $page->where('auth.client.needsTwoFactor', true));
    // Technical people commit nothing, and are not asked.
    $this->actingAs($this->technical)->get('/client')->assertInertia(fn ($page) => $page->where('auth.client.needsTwoFactor', false));

    turnOnTwoStep($this->signatory);
    turnOnTwoStep($this->finance);

    $this->actingAs($this->signatory)->post($accept, ['confirm' => true])->assertSessionHas('success');
    $this->actingAs($this->finance)->postJson("/client/payments/{$this->payment->id}/checkout")->assertOk();
    $this->actingAs($this->finance)->get('/client')->assertInertia(fn ($page) => $page->where('auth.client.needsTwoFactor', false));
});

it('can be switched off for an install', function () {
    config(['company.clients.two_factor_to_commit' => false]);

    $this->actingAs($this->finance)->postJson("/client/payments/{$this->payment->id}/checkout")->assertOk();
    $this->actingAs($this->finance)->get('/client')->assertInertia(fn ($page) => $page->where('auth.client.needsTwoFactor', false));
});

it('shows the organisation\'s owners who did what, and nobody else', function () {
    turnOnTwoStep($this->signatory);
    $this->actingAs($this->signatory)->post("/client/projects/{$this->project->id}/milestones/{$this->milestone->id}/accept", ['confirm' => true]);
    // Our own staff's actions on the organisation are not theirs to see here.
    $this->actingAs($this->owner)->post('/admin/billing', ['user_id' => $this->acme->id, 'title' => 'Second', 'amount' => 500, 'tax_rate' => 18]);

    $this->actingAs($this->finance)->get('/client/activity')->assertForbidden();

    $this->actingAs($this->acme)->get('/client/activity')->assertOk()
        ->assertInertia(fn ($page) => $page->component('client/Activity')
            ->where('table.rows', fn ($rows) => collect($rows)->pluck('action')->contains('Accepted a milestone')
                && collect($rows)->every(fn ($row) => $row['who'] === $this->signatory->name)));

    $this->actingAs($this->acme)->get("/client/activity?filter[actor_id]={$this->finance->id}")
        ->assertInertia(fn ($page) => $page->has('table.rows', 0));
});

<?php

use App\Mail\TemplateTest;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\LeadAcknowledgement;
use App\Notifications\WelcomeCredentials;
use App\Support\MessageCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create(['email' => 'owner@example.test']);
});

function template(string $key): MessageTemplate
{
    MessageCatalogue::sync();

    return MessageTemplate::query()->where('key', $key)->sole();
}

it('has every message ready to edit without a seeder, each with what it can and must say', function () {
    expect(MessageTemplate::query()->count())->toBe(0);

    $this->actingAs($this->owner)->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('templates', count(MessageCatalogue::MESSAGES))
            ->where('templates.0.key', 'lead.acknowledgement')
            ->where('templates.0.isActive', false)
            ->where('templates.0.variables.1', [
                'name' => 'reference',
                'description' => 'The enquiry\'s reference',
                'sample' => 'ENQ-7K2M9',
                'required' => true,
            ]));
});

it('refuses a placeholder the message does not fill in', function (string $body) {
    $template = template('welcome.client.email');

    $this->actingAs($this->owner)
        ->put("/admin/settings/templates/{$template->id}", ['subject' => 'Hi', 'body' => $body, 'is_active' => true])
        ->assertSessionHasErrors('body');

    expect($template->fresh()->is_active)->toBeFalse();
})->with([
    'misspelt' => ['Use {{email}} and {{pasword}}.'],
    'spaced' => ['Use {{ email }} and {{password}}.'],
    'from another message' => ['Use {{email}}, {{password}} and {{reference}}.'],
]);

it('refuses wording that leaves out what the message is for', function () {
    $template = template('welcome.client.email');

    $this->actingAs($this->owner)
        ->put("/admin/settings/templates/{$template->id}", ['subject' => 'Welcome', 'body' => 'Welcome aboard, {{name}}. Sign in with {{email}}.', 'is_active' => true])
        ->assertSessionHasErrors(['body' => 'This message has to include {{password}}, or the person receiving it cannot do what it is for.']);
});

it('saves good wording and the welcome email uses it once switched on', function () {
    $template = template('welcome.client.email');

    $this->actingAs($this->owner)
        ->put("/admin/settings/templates/{$template->id}", [
            'subject' => 'Welcome to {{company}}, {{name}}',
            'body' => "Your account is ready.\n\nSign in with {{email}} and {{password}}.",
            'is_active' => true,
        ])
        ->assertSessionHasNoErrors();

    $account = User::factory()->create(['name' => 'Ravi Kumar', 'email' => 'ravi@example.test']);
    $mail = (new WelcomeCredentials($account, 'Secret-123'))->toMail($account);

    expect($mail->subject)->toBe('Welcome to '.config('app.name').', Ravi Kumar')
        ->and($mail->introLines)->toContain('Sign in with ravi@example.test and Secret-123.');

    expect(AuditLog::query()->where('subject_type', MessageTemplate::class)->exists())->toBeTrue();
});

it('makes the enquiry acknowledgement follow its template, which it used to ignore', function () {
    $template = template('lead.acknowledgement');
    $template->update(['subject' => 'Got it: {{reference}}', 'body' => 'Hello again. Your reference is {{reference}}.', 'is_active' => true]);

    $lead = new Lead(['name' => 'Asha', 'email' => 'asha@example.test', 'reference' => 'ENQ-1']);
    $mail = (new LeadAcknowledgement($lead))->toMail($lead);

    expect($mail->subject)->toBe('Got it: ENQ-1')
        ->and($mail->introLines)->toBe(['Hello again. Your reference is ENQ-1.']);

    $template->update(['is_active' => false]);
    expect((new LeadAcknowledgement($lead))->toMail($lead)->subject)->toBe('We have your enquiry (ENQ-1)');
});

it('previews the wording as typed through the real message, saving nothing', function () {
    $template = template('welcome.client.email');

    $this->actingAs($this->owner)
        ->postJson("/admin/settings/templates/{$template->id}/preview", [
            'subject' => 'Hello {{name}}',
            'body' => 'Your password is {{password}} for {{email}}.',
        ])
        ->assertOk()
        ->assertJsonPath('subject', 'Hello Asha Verma')
        ->assertJson(fn ($json) => $json->where('html', fn ($html) => str_contains($html, 'Your password is Tq7-mVx4-Lp2 for asha@example.com.'))->etc());

    expect($template->fresh()->subject)->toBe('Your {{company}} account is ready');

    $this->actingAs($this->owner)
        ->postJson("/admin/settings/templates/{$template->id}/preview", ['subject' => 'x', 'body' => 'Missing the password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

it('counts an SMS in parts, and knows a rupee sign shortens them', function () {
    $template = template('welcome.client.sms');

    $this->actingAs($this->owner)
        ->postJson("/admin/settings/templates/{$template->id}/preview", ['body' => 'Password {{password}}'])
        ->assertOk()
        ->assertJson(['text' => 'Password Tq7-mVx4-Lp2', 'length' => 21, 'parts' => 1, 'unicode' => false]);

    $this->actingAs($this->owner)
        ->postJson("/admin/settings/templates/{$template->id}/preview", ['body' => str_repeat('₹', 71).' {{password}}'])
        ->assertOk()
        ->assertJson(['unicode' => true, 'parts' => 2]);
});

it('sends a test of the wording as typed to the person editing it', function () {
    Mail::fake();
    $template = template('lead.acknowledgement');

    $this->actingAs($this->owner)
        ->post("/admin/settings/templates/{$template->id}/test", ['subject' => 'Ref {{reference}}', 'body' => 'Thanks. {{reference}}'])
        ->assertSessionHas('success');

    Mail::assertSent(TemplateTest::class, fn (TemplateTest $mail) => $mail->hasTo('owner@example.test')
        && $mail->envelope()->subject === '[Test] Ref ENQ-7K2M9'
        && str_contains($mail->renderedHtml, 'Thanks. ENQ-7K2M9'));

    $sms = template('welcome.client.sms');
    $this->actingAs($this->owner)->post("/admin/settings/templates/{$sms->id}/test", ['body' => '{{password}}'])->assertNotFound();
});

it('lets staff who view settings preview, but not save or send', function () {
    $viewer = User::factory()->admin()->create();
    $viewer->permissions()->attach(Permission::query()->where('key', 'settings.view')->sole());
    $template = template('lead.acknowledgement');

    $this->actingAs($viewer->fresh())->postJson("/admin/settings/templates/{$template->id}/preview", ['subject' => 's', 'body' => '{{reference}}'])->assertOk();
    $this->actingAs($viewer->fresh())->put("/admin/settings/templates/{$template->id}", ['subject' => 's', 'body' => '{{reference}}'])->assertForbidden();
    $this->actingAs($viewer->fresh())->post("/admin/settings/templates/{$template->id}/test", ['subject' => 's', 'body' => '{{reference}}'])->assertForbidden();
});

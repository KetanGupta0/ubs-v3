<?php

use App\Models\AssistantConversation;
use App\Models\AssistantGap;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Lead;
use App\Models\LegalDocument;
use App\Models\Permission;
use App\Models\RegistrationForm;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LeadAcknowledgement;
use App\Notifications\LeadReceived;
use App\Services\Ai\ClaudeChat;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\SiteTools;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopCatalogue;

/*
 * The assistant on the public site (§9.2): off until switched on with the
 * key and a published privacy policy that says what happens to what visitors
 * type; answers only from the catalogue tools; passes details on only when
 * the visitor presses Send. The Messages API is stood in for.
 */

/** Stands in for the API: answers from a script, and records what it was asked. */
class FakeAssistantChat extends ClaudeChat
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @var array<int, array<string, mixed>> */
    public array $script = [];

    public bool $fail = false;

    public function configured(): bool
    {
        return true;
    }

    public function send(array $system, array $messages, array $tools): array
    {
        $this->calls[] = compact('system', 'messages', 'tools');

        if ($this->fail) {
            throw new RuntimeException('Overloaded');
        }

        $next = array_shift($this->script) ?? ['text' => 'Fine.'];
        $uses = $next['tool_uses'] ?? [];

        return [
            'stop' => $next['stop'] ?? ($uses ? 'tool_use' : 'end_turn'),
            'content' => $uses ? array_map(fn (array $use) => ['type' => 'tool_use'] + $use, $uses) : [['type' => 'text', 'text' => $next['text'] ?? '']],
            'text' => $next['text'] ?? '',
            'tool_uses' => $uses,
            'input_tokens' => 1000,
            'output_tokens' => 100,
        ];
    }
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->chat = new FakeAssistantChat;
    app()->instance(ClaudeChat::class, $this->chat);

    // On, as the owner would leave it: the policy published, the switch on.
    $this->switchOn = function () {
        LegalDocument::query()->where('key', 'privacy')->sole()->draft()->sole()->publish($this->owner);
        $this->actingAs($this->owner)->put('/admin/assistant', ['enabled' => true, 'policy_ack' => true, 'retention_days' => 30, 'daily_limit' => 100])
            ->assertSessionHasNoErrors();
        auth()->logout();
    };
    $this->begin = function (): string {
        return $this->postJson('/assistant/start', ['agree' => true, 'page' => '/solutions'])->assertOk()->json('token');
    };
    $this->say = fn (string $token, string $message) => $this->postJson('/assistant/message', ['token' => $token, 'message' => $message]);
});

/* ------------------------------------------------------------- switching on */

it('stays off until switched on, with the key and a published policy that says so', function () {
    $this->get('/')->assertInertia(fn ($page) => $page->where('assistant', null));
    $this->postJson('/assistant/start', ['agree' => true])->assertNotFound();

    $policy = LegalDocument::query()->where('key', 'privacy')->sole();
    expect($policy->draft()->sole()->body)->toContain('## The assistant on our website')
        ->and(Setting::get('assistant.policy_version'))->toBe($policy->draft()->sole()->version);

    $settings = ['enabled' => true, 'retention_days' => 30, 'daily_limit' => 100];

    $this->actingAs($this->owner)->put('/admin/assistant', $settings + ['policy_ack' => true])
        ->assertSessionHasErrors(['enabled' => 'Publish version 2 of the privacy policy first: it says what happens to what visitors type.']);

    $policy->draft()->sole()->publish($this->owner);

    $this->actingAs($this->owner)->put('/admin/assistant', $settings)
        ->assertSessionHasErrors(['enabled' => 'Tick that the published privacy policy tells visitors about the assistant.']);
    $this->actingAs($this->owner)->put('/admin/assistant', ['enabled' => true, 'policy_ack' => true, 'retention_days' => 120, 'daily_limit' => 100])
        ->assertSessionHasErrors('retention_days');
    $this->actingAs($this->owner)->put('/admin/assistant', $settings + ['policy_ack' => true])->assertSessionHas('success');

    expect(AuditLog::query()->where('action', 'assistant.settings')->sole()->changes['enabled'])->toBe(['from' => false, 'to' => true]);

    auth()->logout();
    $this->get('/')->assertInertia(fn ($page) => $page->where('assistant.retentionDays', 30));
});

it('sends nothing until the visitor has agreed to the notice', function () {
    ($this->switchOn)();

    $this->postJson('/assistant/start', ['page' => '/'])->assertStatus(422)->assertJsonValidationErrors(['agree' => 'Agree to the notice to start.']);

    $token = ($this->begin)();

    expect(strlen($token))->toBe(40)
        ->and(AssistantConversation::query()->sole())->token_hash->toBe(hash('sha256', $token))->page->toBe('/solutions')
        ->and($this->chat->calls)->toBe([]);

    ($this->say)(str_repeat('x', 40), 'Hello')->assertNotFound();
});

/* --------------------------------------------------------------- answering */

it('answers from the catalogue tools, with links to this site only', function () {
    ($this->switchOn)();
    ShopCatalogue::product('School office', ['offerings' => ['custom_build', 'source_code', 'demo'], 'demo_url' => 'https://demo.example.test']);
    $token = ($this->begin)();

    $this->chat->script = [
        ['tool_uses' => [['id' => 'tu_1', 'name' => 'search_catalogue', 'input' => ['query' => 'school office', 'kind' => 'any']]]],
        ['text' => 'We have [School office](/solutions/school-office), built to order or sold with its source code. Also see [this](https://elsewhere.example).'],
    ];

    $answer = ($this->say)($token, 'Do you have software for a school office?')->assertOk()->json();

    expect($answer['html'])
        ->toContain('<a href="/solutions/school-office">School office</a>')
        ->not->toContain('elsewhere.example')
        ->toContain('this')
        ->and($answer['closed'])->toBeFalse()
        ->and($answer['offer'])->toBeNull();

    // What the model was told, and what the tool gave it.
    $first = $this->chat->calls[0];
    expect($first['system'][0]['text'])->toContain('Never invent a price')
        ->and(collect($first['tools'])->pluck('name')->all())->toBe(['search_catalogue', 'get_item', 'open_intakes', 'site_pages', 'record_gap', 'offer_contact'])
        ->and(collect($first['tools'])->every(fn (array $tool) => $tool['strict'] === true && $tool['inputSchema']['additionalProperties'] === false))->toBeTrue()
        ->and($first['messages'])->toBe([['role' => 'user', 'content' => 'Do you have software for a school office?']]);

    $result = json_decode($this->chat->calls[1]['messages'][2]['content'][0]['content'], true);
    expect($this->chat->calls[1]['messages'][2]['content'][0]['toolUseID'])->toBe('tu_1')
        ->and($result['results'][0])->toMatchArray([
            'kind' => 'solution',
            'url' => '/solutions/school-office',
            'live_demo' => true,
            'price' => ['built_to_order' => '₹3L – ₹9L, indicative; settled after a conversation about what is needed', 'fixed_price' => 'from ₹49,999 + GST'],
        ]);

    $conversation = AssistantConversation::query()->sole();
    expect($conversation)->visitor_messages->toBe(1)->input_tokens->toBe(2000)
        ->and($conversation->messages()->pluck('role')->all())->toBe(['visitor', 'assistant'])
        ->and($conversation->messages()->where('role', 'assistant')->sole()->tools)->toBe([['name' => 'search_catalogue', 'input' => ['query' => 'school office', 'kind' => 'any']]]);

    // The next message carries the conversation so far.
    $this->chat->script = [['text' => 'Yes.']];
    ($this->say)($token, 'Is there a demo?')->assertOk();
    expect(collect(end($this->chat->calls)['messages'])->pluck('role')->all())->toBe(['user', 'assistant', 'user']);
});

it('never shows what the public site hides', function () {
    $tools = app(SiteTools::class);
    $conversation = AssistantConversation::query()->create(['token_hash' => str_repeat('a', 64)]);

    ShopCatalogue::product('Hidden clinic', ['is_published' => false]);
    $visible = Course::query()->create(['title' => 'Python basics', 'slug' => 'python-basics', 'type' => 'course', 'tagline' => 'Start coding', 'summary' => 'Python from zero.', 'is_published' => true, 'visibility' => 'public']);
    $private = Course::query()->create(['title' => 'Python for a college', 'slug' => 'python-college', 'type' => 'course', 'tagline' => 'For one college', 'summary' => 'Python for one college only.', 'is_published' => true, 'visibility' => 'lms_only']);
    $internship = Course::query()->create(['title' => 'Python internship', 'slug' => 'python-internship', 'type' => 'internship', 'tagline' => 'Real work', 'summary' => 'Python on a live project.', 'is_published' => true, 'visibility' => 'public']);

    foreach ([$visible, $private] as $course) {
        RegistrationForm::query()->create(['title' => "Join {$course->title}", 'slug' => 'join-'.$course->slug, 'course_id' => $course->id, 'is_active' => true]);
    }
    Batch::query()->create(['course_id' => $private->id, 'name' => 'College batch', 'code' => 'PC-1', 'starts_on' => today()->addWeek(), 'status' => 'upcoming', 'is_published' => true]);
    Batch::query()->create(['course_id' => $visible->id, 'name' => 'December batch', 'code' => 'PB-1', 'starts_on' => today()->addWeek(), 'status' => 'upcoming', 'is_published' => true, 'capacity' => 30, 'seats_taken' => 12]);

    $found = collect($tools->run('search_catalogue', ['query' => 'python clinic', 'kind' => 'any'], $conversation)['results']);

    expect($found->pluck('url')->sort()->values()->all())->toBe(['/internships/python-internship', '/training/python-basics']);

    expect($tools->run('get_item', ['kind' => 'internship', 'slug' => 'python-basics'], $conversation))->toHaveKey('error')
        ->and($tools->run('get_item', ['kind' => 'course', 'slug' => 'python-college'], $conversation))->toHaveKey('error');

    $intakes = $tools->run('open_intakes', [], $conversation);
    expect(collect($intakes['batches'])->pluck('batch')->all())->toBe(['December batch'])
        ->and($intakes['batches'][0]['seats_left'])->toBe(18)
        ->and(collect($intakes['registration_forms'])->pluck('url')->all())->toBe(['/join/join-python-basics']);

    // A search term is a search term, not a pattern.
    expect($tools->run('search_catalogue', ['query' => '%_%', 'kind' => 'any'], $conversation)['results'])->toBe([]);
});

/* ------------------------------------------------------------ passing on */

it('puts details on a card and passes them on only when the visitor presses Send', function () {
    Notification::fake();
    ($this->switchOn)();
    $token = ($this->begin)();

    $this->chat->script = [
        ['tool_uses' => [['id' => 'tu_1', 'name' => 'offer_contact', 'input' => ['name' => 'Priya Nair', 'email' => 'PRIYA@example.test', 'phone' => '', 'need' => 'A website for our school.', 'interest' => 'service']]]],
        ['text' => 'Check the card and press Send if it is right.'],
    ];

    $answer = ($this->say)($token, 'Yes, please ask somebody to call me. Priya, priya@example.test')->json();

    expect($answer['offer'])->toBe(['name' => 'Priya Nair', 'email' => 'priya@example.test', 'mobile' => null, 'need' => 'A website for our school.'])
        ->and(Lead::query()->count())->toBe(0);

    $this->postJson('/assistant/lead', ['token' => $token, 'name' => 'Priya Nair', 'email' => 'priya@example.test', 'mobile' => '+91 98765 43210', 'need' => 'A website for our school, with admissions.'])
        ->assertOk()
        ->assertJson(['sent' => true]);

    $lead = Lead::query()->sole();
    expect($lead)
        ->email->toBe('priya@example.test')
        ->mobile->toBe('+919876543210')
        ->interest->toBe('service')
        ->source_page->toBe('/solutions')
        ->and($lead->message)->toStartWith('A website for our school, with admissions.')->toContain('Sent from the assistant on the website')->toContain('Visitor: Yes, please ask somebody to call me.')
        ->and(AssistantConversation::query()->sole())->outcome->toBe('lead')->lead_id->toBe($lead->id);

    Notification::assertSentTo($this->owner, LeadReceived::class);
    Notification::assertSentOnDemand(LeadAcknowledgement::class);

    $this->postJson('/assistant/lead', ['token' => $token, 'name' => 'Priya Nair', 'email' => 'priya@example.test', 'need' => 'Again'])
        ->assertStatus(422)->assertJson(['message' => 'Your details were already sent.']);
});

it('sends nothing when no card was shown, and notes what it could not answer', function () {
    ($this->switchOn)();
    $token = ($this->begin)();

    $this->postJson('/assistant/lead', ['token' => $token, 'name' => 'Somebody', 'email' => 'somebody@example.test', 'need' => 'Anything'])
        ->assertStatus(422)->assertJson(['message' => 'There is nothing to send.']);

    $this->chat->script = [
        ['tool_uses' => [['id' => 'tu_1', 'name' => 'record_gap', 'input' => ['question' => 'Do you build mobile apps for iPhone?']]]],
        ['text' => 'I do not know. The [contact page](/contact) reaches the team.'],
    ];
    ($this->say)($token, 'Do you build iPhone apps?')->assertOk();

    expect(AssistantGap::query()->sole()->question)->toBe('Do you build mobile apps for iPhone?')
        ->and(Lead::query()->count())->toBe(0);
});

/* ---------------------------------------------------------------- limits */

it('hands over to the contact page when a conversation, a day or an address has had enough', function () {
    ($this->switchOn)();
    $token = ($this->begin)();
    AssistantConversation::query()->update(['visitor_messages' => Assistant::MAX_VISITOR_MESSAGES]);

    ($this->say)($token, 'One more')->assertOk()->assertJson(['closed' => true, 'reply' => Assistant::HANDOVER]);
    expect(AssistantConversation::query()->sole()->outcome)->toBe('capped')
        ->and($this->chat->calls)->toBe([]);

    // A day's limit.
    Setting::put('assistant.daily_limit', 1, 'assistant');
    $this->postJson('/assistant/start', ['agree' => true])->assertOk()->assertJson(['closed' => true]);

    // An address starting conversation after conversation.
    Setting::put('assistant.daily_limit', 0, 'assistant');
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/assistant/start', ['agree' => true])->assertJsonStructure(['token']);
    }
    $this->postJson('/assistant/start', ['agree' => true])->assertJson(['closed' => true]);
});

it('says so plainly when the model declines or the API fails', function () {
    ($this->switchOn)();
    $token = ($this->begin)();

    $this->chat->fail = true;
    $answer = ($this->say)($token, 'Hello')->assertOk()->json();
    expect($answer['closed'])->toBeFalse()->and($answer['html'])->toContain('cannot answer just now');

    $this->chat->fail = false;
    $this->chat->script = [['stop' => 'refusal', 'text' => '']];
    ($this->say)($token, 'Something it will not do')->assertOk()->assertJson(['closed' => true, 'reply' => Assistant::HANDOVER]);
    expect(AssistantConversation::query()->sole()->outcome)->toBe('refused');
});

/* ---------------------------------------------------------- keeping it */

it('deletes conversations after the period set in the panel, and keeps the leads', function () {
    $old = AssistantConversation::query()->create(['token_hash' => str_repeat('b', 64)]);
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    $old->messages()->create(['role' => 'visitor', 'body' => 'Old question', 'created_at' => now()->subDays(31)]);
    $lead = Lead::query()->create(['name' => 'Kept', 'email' => 'kept@example.test', 'message' => 'From the assistant', 'interest' => 'general', 'status' => 'new']);
    $old->forceFill(['lead_id' => $lead->id])->save();
    $recent = AssistantConversation::query()->create(['token_hash' => str_repeat('c', 64)]);

    Setting::put('assistant.retention_days', 30, 'assistant');
    $this->artisan('assistant:prune')->expectsOutputToContain('Deleted 1 conversation')->assertSuccessful();

    expect(AssistantConversation::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(Lead::query()->find($lead->id))->not->toBeNull();
});

it('shows its conversations and gaps to those who run it, and to nobody else', function () {
    $conversation = AssistantConversation::query()->create(['token_hash' => str_repeat('d', 64), 'page' => '/training', 'visitor_messages' => 1]);
    $conversation->messages()->create(['role' => 'visitor', 'body' => 'Which course for a beginner?', 'created_at' => now()]);
    $conversation->messages()->create(['role' => 'assistant', 'body' => 'Try [Python basics](/training/python-basics).', 'tools' => [['name' => 'search_catalogue', 'input' => ['query' => 'beginner', 'kind' => 'course']]], 'created_at' => now()]);
    AssistantGap::query()->create(['assistant_conversation_id' => $conversation->id, 'question' => 'Is there a weekend batch?', 'created_at' => now()]);

    $manager = User::factory()->admin()->create();
    $manager->permissions()->attach(Permission::query()->where('key', 'assistant.manage')->sole());

    $this->actingAs($manager)->get('/admin/assistant')->assertInertia(fn ($page) => $page
        ->component('admin/assistant/Index')
        ->where('table.rows.0.first_question', 'Which course for a beginner?')
        ->where('gaps.0.question', 'Is there a weekend batch?')
        ->where('state.live', false));

    $this->actingAs($manager)->get("/admin/assistant/conversations/{$conversation->id}")->assertInertia(fn ($page) => $page
        ->where('messages.1.html', fn ($html) => str_contains($html, '<a href="/training/python-basics">'))
        ->where('messages.1.tools.0', 'search_catalogue {"query":"beginner","kind":"course"}'));

    $this->actingAs(User::factory()->admin()->create())->get('/admin/assistant')->assertForbidden();
});

it('adds its paragraph to the draft in progress, or to a new draft, and never publishes', function () {
    $migration = require database_path('migrations/2026_11_19_000005_add_assistant_to_privacy_draft.php');
    $policy = LegalDocument::query()->where('key', 'privacy')->sole();

    // A draft in progress already has it once; running again adds nothing.
    $migration->up();
    expect(substr_count($policy->draft()->sole()->body, '## The assistant on our website'))->toBe(1);

    // Once that is published, a new draft carries it, and that version is the one needed.
    $policy->draft()->sole()->publish($this->owner);
    $published = $policy->versions()->where('status', 'published')->max('version');
    $migration->up();

    $draft = $policy->fresh()->draft()->sole();
    expect($draft->version)->toBe($published + 1)
        ->and($draft->status)->toBe('draft')
        ->and(Setting::get('assistant.policy_version'))->toBe($published + 1);
});

<?php

use App\Models\AuditLog;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

function legalDocument(string $key): LegalDocument
{
    return LegalDocument::query()->where('key', $key)->sole();
}

/** Save (and optionally publish) the next version of a document as the owner. */
function writeLegal($test, string $key, array $fields = [], bool $publish = false)
{
    return $test->actingAs($test->owner)->post("/admin/legal/{$key}", [
        'intro' => 'What this covers.',
        'body' => "## First section\n\nThe words.",
        'change_note' => 'Rewrote the first section.',
        ...$fields,
        'publish' => $publish,
    ]);
}

it('serves the privacy policy and terms from their first published version', function () {
    $publishedAt = legalDocument('privacy')->current->published_at;

    // Until November 2026 the page printed whatever today was as "last updated".
    Carbon::setTestNow($publishedAt->copy()->addMonths(3));

    $this->get('/legal/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/Legal')
            ->where('mode', 'current')
            ->where('document.title', 'Privacy policy')
            ->where('version.number', 1)
            ->where('version.publishedAt', $publishedAt->format('j F Y'))
            ->where('version.html', fn ($html) => str_contains($html, '<h2>What we collect</h2>')
                && str_contains($html, 'we do not add you to a mailing list'))
            ->where('seo.noindex', false));

    $this->get('/legal/terms')->assertOk()->assertInertia(fn ($page) => $page
        ->where('version.html', fn ($html) => str_contains($html, '<h2>Governing law</h2>')));
});

it('keeps a document with nothing published off the site', function () {
    $this->get('/legal/refunds')->assertNotFound();
    $this->get('/legal/cookies')->assertNotFound();

    $this->get('/sitemap.xml')->assertOk()
        ->assertSee(url('/legal/privacy'), false)
        ->assertDontSee(url('/legal/refunds'), false);

    $this->get('/')->assertInertia(fn ($page) => $page->where('legal', [
        ['title' => 'Privacy policy', 'href' => '/legal/privacy'],
        ['title' => 'Terms of service', 'href' => '/legal/terms'],
    ]));
});

it('changes nothing on the site until a draft is published', function () {
    writeLegal($this, 'privacy', ['body' => "## Changed\n\nNew words."])
        ->assertRedirect('/admin/legal/privacy')
        ->assertSessionHas('success');

    $draft = legalDocument('privacy')->draft;
    expect($draft->version)->toBe(2)->and($draft->isPublished())->toBeFalse();

    $this->get('/legal/privacy')->assertInertia(fn ($page) => $page
        ->where('version.number', 1)
        ->where('version.html', fn ($html) => ! str_contains($html, 'New words')));

    // Nor can a visitor reach the draft by guessing its number.
    $this->get('/legal/privacy/versions/2')->assertNotFound();

    // Saving again edits the same draft rather than starting another.
    writeLegal($this, 'privacy', ['body' => "## Changed again\n\nNewer words."]);
    expect(legalDocument('privacy')->versions()->where('status', 'draft')->count())->toBe(1)
        ->and(legalDocument('privacy')->draft->body)->toContain('Newer words');
});

it('publishes a new version and keeps the old one readable, out of search', function () {
    $first = legalDocument('terms')->current;

    $this->travel(1)->days();
    writeLegal($this, 'terms', ['body' => "## Payment\n\nInvoices are due in fifteen days."], publish: true)
        ->assertSessionHasNoErrors();

    $this->get('/legal/terms')->assertInertia(fn ($page) => $page
        ->where('version.number', 2)
        ->where('version.changeNote', 'Rewrote the first section.')
        ->where('version.html', fn ($html) => str_contains($html, 'fifteen days'))
        ->has('history', 2)
        ->where('history.0.current', true)
        ->where('history.1.href', '/legal/terms/versions/1'));

    $this->get('/legal/terms/versions/1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('mode', 'archived')
            ->where('version.number', 1)
            ->where('version.publishedAt', $first->published_at->format('j F Y'))
            ->where('version.supersededAt', now()->format('j F Y'))
            ->where('version.html', fn ($html) => str_contains($html, 'Governing law'))
            ->where('seo.noindex', true));

    // The version in force has one address.
    $this->get('/legal/terms/versions/2')->assertRedirect('/legal/terms');

    $entry = AuditLog::query()->where('action', 'legal.published')->sole();
    expect($entry->actor_id)->toBe($this->owner->id);
});

it('never changes or deletes a published version, whichever code path asks', function () {
    $published = legalDocument('privacy')->current;

    expect(fn () => $published->update(['body' => 'Quietly rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $published->delete())->toThrow(LogicException::class)
        ->and($published->fresh()->body)->toContain('What we collect');
});

it('asks what changed, and refuses to publish an unchanged copy', function () {
    $current = legalDocument('privacy')->current;

    writeLegal($this, 'privacy', ['change_note' => ''], publish: true)
        ->assertSessionHasErrors('change_note');

    writeLegal($this, 'privacy', ['intro' => $current->intro, 'body' => $current->body], publish: true)
        ->assertSessionHasErrors('body');

    expect(legalDocument('privacy')->current->version)->toBe(1);
});

it('publishes a first version without a change note, and the footer and sitemap pick it up', function () {
    writeLegal($this, 'refunds', ['body' => "## Courses\n\nAsk within seven days.", 'change_note' => ''], publish: true)
        ->assertSessionHasNoErrors();

    $this->get('/legal/refunds')->assertOk()->assertInertia(fn ($page) => $page
        ->where('document.title', 'Refund and cancellation policy')
        ->where('version.number', 1)
        ->where('legal.2', ['title' => 'Refund and cancellation policy', 'href' => '/legal/refunds']));

    $this->get('/sitemap.xml')->assertSee(url('/legal/refunds'), false);
});

it('strips typed HTML and refuses script links', function () {
    writeLegal($this, 'terms', [
        'body' => "## Terms\n\n<script>alert(1)</script>\n\nSome <b>raw</b> text, [click](javascript:alert(1)) and [ok](https://example.com).",
    ], publish: true);

    $this->get('/legal/terms')->assertInertia(fn ($page) => $page
        ->where('version.html', fn ($html) => ! str_contains($html, '<script')
            && ! str_contains($html, '<b>')
            && ! str_contains($html, 'javascript:')
            && str_contains($html, 'href="https://example.com"')));
});

it('lets staff read any version as a preview, kept out of search', function () {
    writeLegal($this, 'privacy', ['body' => "## Draft\n\nNot yet."]);

    $this->actingAs($this->owner)->get('/admin/legal/privacy/versions/2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/Legal')
            ->where('mode', 'preview')
            ->where('version.publishedAt', null)
            ->where('version.html', fn ($html) => str_contains($html, 'Not yet.'))
            ->where('seo.noindex', true));

    $this->actingAs($this->owner)->get('/admin/legal/privacy/versions/9')->assertNotFound();
});

it('starts the editor from the words in force, and throws a draft away', function () {
    $this->actingAs($this->owner)->get('/admin/legal/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/legal/Edit')
            ->where('document.nextNumber', 2)
            ->where('form.body', legalDocument('privacy')->current->body)
            ->where('form.change_note', '')
            ->has('history', 1));

    writeLegal($this, 'privacy');
    $this->actingAs($this->owner)->delete('/admin/legal/privacy/draft')->assertRedirect('/admin/legal/privacy');

    expect(legalDocument('privacy')->draft)->toBeNull()
        ->and(legalDocument('privacy')->current->version)->toBe(1);
});

it('lists the documents for staff who may edit them, and nobody else', function () {
    $this->actingAs($this->owner)->get('/admin/legal')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('table.rows', count(LegalDocument::DOCUMENTS))
            ->where('table.rows.2.in_force', 'Not published'));

    // Writing pages for the site is not the same trust as the terms.
    $writer = User::factory()->admin()->create();
    $writer->permissions()->attach(Permission::query()->where('key', 'content.manage')->sole());
    $this->actingAs($writer->fresh())->get('/admin/legal')->assertForbidden();
    $this->actingAs($writer->fresh())->post('/admin/legal/privacy', ['body' => 'x', 'publish' => true])->assertForbidden();

    $legal = User::factory()->admin()->create();
    $legal->permissions()->attach(Permission::query()->where('key', 'legal.manage')->sole());
    $this->actingAs($legal->fresh())->get('/admin/legal/terms')->assertOk();

    expect(LegalDocumentVersion::query()->where('status', 'draft')->count())->toBe(0);
});

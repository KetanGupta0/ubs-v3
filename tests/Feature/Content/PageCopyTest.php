<?php

use App\Models\AuditLog;
use App\Models\PageCopyEntry;
use App\Models\Permission;
use App\Models\User;
use App\Support\PageCopy;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

/** The page's wording as sent to the browser. Its keys hold dots, so read them directly. */
function copyOn($response): array
{
    return $response->viewData('page')['props']['copy'];
}

function saveCopy($test, string $page, array $values)
{
    return $test->actingAs($test->owner)->put("/admin/copy/{$page}", ['values' => $values]);
}

it('renders the starting wording until somebody changes it', function () {
    $response = $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->where('copyPreview', false));
    $copy = copyOn($response);

    expect($copy['hero.title'])->toBe('We build the software, and we train the people who *run it*.')
        ->and($copy['technology.list'])->toHaveCount(14)
        ->and($copy['technology.list'][0])->toBe('Laravel')
        ->and($copy['catalogue.body'])->toStartWith('{solutions} products across {categories} industries');
});

it('keeps an edit as a draft that only staff previewing can see', function () {
    saveCopy($this, 'home', ['hero.title' => 'Software that *lasts*.'])->assertSessionHasNoErrors();

    $live = 'We build the software, and we train the people who *run it*.';

    // Visitors: unchanged.
    expect(copyOn($this->get('/'))['hero.title'])->toBe($live);

    // A visitor asking for the preview gets the live page, and it is indexable.
    $this->app['auth']->forgetGuards();
    $visitor = $this->get('/?preview=copy')->assertInertia(fn ($page) => $page->where('copyPreview', false)->where('seo.noindex', false));
    expect(copyOn($visitor)['hero.title'])->toBe($live);

    // Staff: the draft, marked and kept out of search.
    $staff = $this->actingAs($this->owner)->get('/?preview=copy')->assertInertia(fn ($page) => $page->where('copyPreview', true)->where('seo.noindex', true));
    expect(copyOn($staff)['hero.title'])->toBe('Software that *lasts*.');
});

it('publishes every draft on the page at once, and records what changed', function () {
    $this->get('/'); // warm the cache, so publishing has to drop it
    saveCopy($this, 'home', ['hero.title' => 'Software that *lasts*.', 'hero.badge' => 'Build · Teach']);

    $this->actingAs($this->owner)->post('/admin/copy/home/publish')->assertSessionHas('success');

    $copy = copyOn($this->get('/'));
    expect($copy['hero.title'])->toBe('Software that *lasts*.')
        ->and($copy['hero.badge'])->toBe('Build · Teach');

    $entry = AuditLog::query()->where('action', 'copy.published')->sole();
    expect($entry->changes['hero.title'])->toBe([
        'from' => 'We build the software, and we train the people who *run it*.',
        'to' => 'Software that *lasts*.',
    ]);
    expect(PageCopyEntry::query()->where('has_draft', true)->count())->toBe(0);
});

it('goes back to the starting wording, leaving nothing stored', function () {
    saveCopy($this, 'home', ['hero.badge' => 'Build · Teach']);
    $this->actingAs($this->owner)->post('/admin/copy/home/publish');

    $default = PageCopy::fields('home')['hero.badge']['default'];
    saveCopy($this, 'home', ['hero.badge' => $default]);
    $this->actingAs($this->owner)->post('/admin/copy/home/publish');

    expect(copyOn($this->get('/'))['hero.badge'])->toBe($default);
    expect(PageCopyEntry::query()->count())->toBe(0);
});

it('throws drafts away without touching what is live', function () {
    saveCopy($this, 'home', ['hero.badge' => 'Live badge']);
    $this->actingAs($this->owner)->post('/admin/copy/home/publish');
    saveCopy($this, 'home', ['hero.badge' => 'Draft badge', 'hero.primary' => 'Draft button']);

    $this->actingAs($this->owner)->delete('/admin/copy/home/drafts')->assertSessionHas('success');

    $copy = copyOn($this->actingAs($this->owner)->get('/?preview=copy'));
    expect($copy['hero.badge'])->toBe('Live badge')
        ->and($copy['hero.primary'])->toBe('Browse what we build');
});

it('refuses wording that would not fit or would show as typed', function (string $key, string $value, string $message) {
    saveCopy($this, 'home', [$key => $value])->assertSessionHasErrors("values.{$key}");

    expect(session('errors')->first("values.{$key}"))->toStartWith($message)
        ->and(PageCopyEntry::query()->count())->toBe(0);
})->with([
    'empty' => ['hero.title', '   ', 'Leave something here'],
    'too long' => ['hero.badge', str_repeat('x', 61), 'Keep this under 60 characters'],
    'two lines in a line' => ['hero.primary', "Browse\nwhat we build", 'Keep this on one line'],
    'a token it does not fill' => ['catalogue.body', '{courses} programmes', '{courses} is not filled in here'],
    'a token where none is filled' => ['hero.body', 'We have {solutions} products', '{solutions} is not filled in here'],
    'too many lines' => ['paths.student_points', "a\nb\nc\nd\ne\nf\ng", 'At most 6 lines'],
]);

it('keeps every starting wording within its own limits', function () {
    foreach (PageCopy::pages() as $page => $definition) {
        foreach (PageCopy::fields($page) as $key => $field) {
            $value = $field['type'] === 'list' ? PageCopy::lines($field['default']) : [$field['default']];

            foreach ($value as $line) {
                expect(mb_strlen($line))->toBeLessThanOrEqual($field['max'], "{$page}: {$key} is longer than its limit");
            }

            preg_match_all('/\{([a-z_]+)\}/', $field['default'], $tokens);
            expect(array_diff($tokens[1], $field['tokens'] ?? []))->toBe([], "{$page}: {$key} uses a token it does not declare");
        }
    }
});

it('lists the pages, and opens each one for editing', function () {
    $this->actingAs($this->owner)->get('/admin/copy')->assertOk()
        ->assertInertia(fn ($page) => $page->has('pages', count(PageCopy::pages())));

    foreach (array_keys(PageCopy::pages()) as $page) {
        $this->actingAs($this->owner)->get("/admin/copy/{$page}")->assertOk();
    }

    $this->actingAs($this->owner)->get('/admin/copy/nowhere')->assertNotFound();
});

it('is only for staff who may write the public site', function () {
    $billing = User::factory()->admin()->create();
    $billing->permissions()->attach(Permission::query()->where('key', 'billing.view')->sole());

    $this->actingAs($billing->fresh())->get('/admin/copy')->assertForbidden();
    $this->actingAs($billing->fresh())->put('/admin/copy/home', ['values' => ['hero.badge' => 'x']])->assertForbidden();

    // Nor does somebody without the permission see drafts by asking for a preview.
    saveCopy($this, 'home', ['hero.badge' => 'Secret draft']);
    expect(copyOn($this->actingAs($billing->fresh())->get('/?preview=copy'))['hero.badge'])->toBe('Software development · Live training');
});

it('gives every fixed page all of its wording, live and in preview', function () {
    foreach (PageCopy::pages() as $page => $definition) {
        $keys = array_keys(PageCopy::fields($page));

        expect(array_keys(copyOn($this->get($definition['path'])->assertOk())))->toBe($keys, "{$page} is missing wording");
        $this->actingAs($this->owner)->get($definition['path'].'?preview=copy')->assertOk()
            ->assertInertia(fn ($inertia) => $inertia->where('copyPreview', true));
        $this->app['auth']->forgetGuards();
    }
});

it('keeps paragraphs apart, and the About page fills in the live product count', function () {
    saveCopy($this, 'about', ['intro.body' => "First paragraph.\n\n\n\nSecond   paragraph."])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/admin/copy/about/publish');

    $this->app['auth']->forgetGuards();
    $response = $this->get('/about');

    expect(copyOn($response)['intro.body'])->toBe("First paragraph.\n\nSecond paragraph.")
        ->and(copyOn($response)['today.body'])->toContain('{solutions} products');
    $response->assertInertia(fn ($page) => $page->has('figures.solutions'));

    saveCopy($this, 'about', ['intro.title' => "Two\n\nlines"])->assertSessionHasErrors('values.intro.title');
});

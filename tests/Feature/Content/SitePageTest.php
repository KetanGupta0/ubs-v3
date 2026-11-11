<?php

use App\Models\AuditLog;
use App\Models\Page;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

function pagePayload(array $overrides = []): array
{
    return [
        'title' => 'Campus hiring drive',
        'summary' => 'What happens on the day, and how to take part.',
        'blocks' => [
            ['type' => 'heading', 'text' => 'On the day', 'level' => 2],
            ['type' => 'text', 'markdown' => "Bring your **laptop** and your [portfolio](https://example.com).\n\n- Arrive by 10\n- Lunch provided"],
            ['type' => 'callout', 'title' => 'Ready?', 'body' => 'Seats are limited.', 'button_label' => 'Register', 'button_href' => '/contact'],
            ['type' => 'faq', 'items' => [['question' => 'Is it free?', 'answer' => 'Yes, *entirely*.']]],
        ],
        ...$overrides,
    ];
}

it('saves a page as a draft that only staff can preview', function () {
    $this->actingAs($this->owner)->post('/admin/pages', pagePayload())
        ->assertRedirect()
        ->assertSessionHas('success', 'Saved as a draft. Preview it before you publish.');

    $page = Page::query()->sole();

    expect($page->slug)->toBe('campus-hiring-drive')
        ->and($page->status)->toBe('draft')
        ->and(AuditLog::query()->where('subject_type', Page::class)->where('action', 'created')->exists())->toBeTrue();

    auth()->logout();
    $this->get('/pages/campus-hiring-drive')->assertNotFound();

    $this->actingAs($this->owner)->get("/admin/pages/{$page->id}/preview")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/CmsPage')
            ->where('preview', true)
            ->where('seo.noindex', true));
});

it('publishes a page to its address, with its blocks rendered', function () {
    $this->actingAs($this->owner)->post('/admin/pages', pagePayload(['publish' => true]));
    auth()->logout();

    $this->get('/pages/campus-hiring-drive')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('page.title', 'Campus hiring drive')
            ->where('page.blocks.0.text', 'On the day')
            ->where('page.blocks.1.html', fn ($html) => str_contains($html, '<strong>laptop</strong>') && str_contains($html, '<li>Arrive by 10</li>'))
            ->where('page.blocks.3.items.0.html', fn ($html) => str_contains($html, '<em>entirely</em>'))
            ->where('seo.noindex', false)
            ->where('seo.description', 'What happens on the day, and how to take part.'));

    $this->get('/sitemap.xml')->assertSee(url('/pages/campus-hiring-drive'));
});

it('never lets HTML or a script link through from the text', function () {
    $this->actingAs($this->owner)->post('/admin/pages', pagePayload([
        'publish' => true,
        'blocks' => [
            ['type' => 'text', 'markdown' => "<script>alert(1)</script>\n\n<img src=x onerror=alert(2)>\n\n[click](javascript:alert(3))"],
        ],
    ]));
    auth()->logout();

    $html = Page::query()->sole()->renderedBlocks()[0]['html'];

    expect($html)->not->toContain('<script')
        ->not->toContain('onerror')
        ->not->toContain('javascript:');
});

it('refuses a button that points anywhere but this site or a secure address', function (string $href) {
    $this->actingAs($this->owner)->post('/admin/pages', pagePayload([
        'blocks' => [['type' => 'callout', 'title' => 'Go', 'button_label' => 'Go', 'button_href' => $href]],
    ]))->assertSessionHasErrors('blocks.0.button_href');

    expect(Page::query()->count())->toBe(0);
})->with(['javascript:alert(1)', 'http://plain.example.com', '//evil.example.com', 'data:text/html,hi']);

it('checks each block for what its type needs', function () {
    $this->actingAs($this->owner)->post('/admin/pages', pagePayload([
        'blocks' => [
            ['type' => 'heading', 'text' => ''],
            ['type' => 'image', 'alt' => ''],
            ['type' => 'faq', 'items' => []],
            ['type' => 'marquee', 'text' => 'nope'],
        ],
    ]))->assertSessionHasErrors(['blocks.3.type']);

    $this->actingAs($this->owner)->post('/admin/pages', pagePayload([
        'blocks' => [
            ['type' => 'heading', 'text' => ''],
            ['type' => 'image', 'alt' => ''],
            ['type' => 'faq', 'items' => []],
        ],
    ]))->assertSessionHasErrors(['blocks.0.text', 'blocks.1.upload', 'blocks.1.alt', 'blocks.2.items']);
});

it('keeps only the fields a block type allows', function () {
    $this->actingAs($this->owner)->post('/admin/pages', pagePayload([
        'blocks' => [['type' => 'heading', 'text' => 'Hi', 'level' => 9, 'onclick' => 'alert(1)', 'html' => '<b>x</b>']],
    ]));

    expect(Page::query()->sole()->blocks)->toBe([['type' => 'heading', 'text' => 'Hi', 'level' => 2]]);
});

it('stores a picture re-encoded on the public disk, and refuses a path it did not store', function () {
    $this->actingAs($this->owner)->post('/admin/pages', pagePayload([
        'blocks' => [['type' => 'image', 'alt' => 'The hall', 'upload' => UploadedFile::fake()->image('hall.png', 2400, 1200)]],
    ]))->assertSessionHasNoErrors();

    $src = Page::query()->sole()->blocks[0]['src'];

    expect($src)->toMatch('#^pages/[0-9a-f-]{36}\.jpg$#')
        ->and(getimagesizefromstring(Storage::disk('public')->get($src))[0])->toBe(1600);

    $this->actingAs($this->owner)->post('/admin/pages', pagePayload([
        'title' => 'Sneaky',
        'blocks' => [['type' => 'image', 'alt' => 'x', 'src' => '../../.env']],
    ]))->assertSessionHasErrors('blocks.0.upload');
});

it('can be kept out of search engines, and taken off the site', function () {
    $this->actingAs($this->owner)->post('/admin/pages', pagePayload(['publish' => true, 'is_indexable' => false]));
    $page = Page::query()->sole();

    $this->get('/pages/campus-hiring-drive')->assertInertia(fn ($p) => $p->where('seo.noindex', true));
    $this->get('/sitemap.xml')->assertDontSee('/pages/campus-hiring-drive');

    $this->actingAs($this->owner)->post("/admin/pages/{$page->id}/unpublish")->assertSessionHas('success');
    auth()->logout();

    $this->get('/pages/campus-hiring-drive')->assertNotFound();
});

it('refuses an address another page already uses', function () {
    $this->actingAs($this->owner)->post('/admin/pages', pagePayload());
    $this->actingAs($this->owner)->post('/admin/pages', pagePayload())->assertSessionHasErrors('slug');
});

it('is only for staff who may write the public site', function () {
    $catalogue = User::factory()->admin()->create();
    $catalogue->permissions()->attach(Permission::query()->where('key', 'catalogue.manage')->sole());

    $this->actingAs($catalogue->fresh())->get('/admin/pages')->assertForbidden();
    $this->actingAs($catalogue->fresh())->post('/admin/pages', pagePayload())->assertForbidden();

    $writer = User::factory()->admin()->create();
    $writer->permissions()->attach(Permission::query()->where('key', 'content.manage')->sole());
    $this->actingAs($writer->fresh())->get('/admin/pages')->assertOk();
});

<?php

use App\Models\AuditLog;
use App\Models\Page;
use App\Models\Permission;
use App\Models\SiteMenu;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

function menuLinks(array $links): array
{
    return ['items' => collect($links)->map(fn ($href, $label) => ['label' => $label, 'href' => $href])->values()->all()];
}

it('starts with the links the layout used to carry, on every public page', function () {
    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('menus.header.0', ['label' => 'Solutions', 'href' => '/solutions', 'external' => false])
        ->has('menus.header', 5)
        ->where('menus.footer.0.title', 'Company')
        ->has('menus.footer.0.links', 7)
        ->where('menus.footer.1.title', 'Account')
        ->has('menus.footer.1.links', 3));
});

it('opens every link in the public menus', function () {
    $menus = SiteMenu::forLayout();

    collect($menus['header'])
        ->concat(collect($menus['footer'])->flatMap(fn ($column) => $column['links']))
        ->each(fn ($link) => $this->get($link['href'])->assertOk());
});

it('saves a reordered header and shows it at once', function () {
    SiteMenu::forLayout(); // warm the cache, so the save has to drop it

    $this->actingAs($this->owner)
        ->put('/admin/menus/header', menuLinks([
            'Training' => '/training',
            'Solutions' => '/solutions',
            'FAQ' => '/faq#payments',
            'Partner' => 'https://example.com/partners',
        ]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $this->get('/')->assertInertia(fn ($page) => $page
        ->has('menus.header', 4)
        ->where('menus.header.0.label', 'Training')
        ->where('menus.header.2.href', '/faq#payments')
        ->where('menus.header.3.external', true));

    $entry = AuditLog::query()->where('action', 'updated')->where('subject_type', SiteMenu::class)->sole();
    expect($entry->changes['links']['from'])->toStartWith('Solutions → /solutions')
        ->and($entry->changes['links']['to'])->toStartWith('Training → /training');
});

it('renames a footer column and needs a title for it', function () {
    $this->actingAs($this->owner)
        ->put('/admin/menus/footer_account', ['title' => '', ...menuLinks(['FAQ' => '/faq'])])
        ->assertSessionHasErrors('title');

    $this->actingAs($this->owner)
        ->put('/admin/menus/footer_account', ['title' => 'Help', ...menuLinks(['FAQ' => '/faq'])])
        ->assertSessionHasNoErrors();

    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('menus.footer.1.title', 'Help')
        ->has('menus.footer.1.links', 1));
});

it('refuses a link that would not go anywhere safe', function (string $href, string $message) {
    $this->actingAs($this->owner)
        ->put('/admin/menus/header', menuLinks(['Broken' => $href]))
        ->assertSessionHasErrors('items.0.href');

    expect(session('errors')->first('items.0.href'))->toStartWith($message);

    expect(SiteMenu::forLayout()['header'][0]['label'])->toBe('Solutions');
})->with([
    'script' => ['javascript:alert(1)', 'Use a path on this site'],
    'another site, no scheme' => ['//evil.example', 'Use a path on this site'],
    'plain http' => ['http://example.com', 'Use a path on this site'],
    'typo' => ['/solutons', 'Nothing on this site answers at /solutons'],
    'unpublished page' => ['/pages/not-yet', '/pages/not-yet is not published'],
    'unpublished legal document' => ['/legal/refunds', '/legal/refunds is not published'],
]);

it('takes a page written in the panel once it is published', function () {
    Page::query()->create(['title' => 'Hiring drive', 'slug' => 'hiring-drive', 'status' => 'published', 'published_at' => now(), 'blocks' => []]);

    $this->actingAs($this->owner)
        ->put('/admin/menus/header', menuLinks(['Hiring' => '/pages/hiring-drive']))
        ->assertSessionHasNoErrors();
});

it('keeps the header short enough to fit', function () {
    $links = collect(range(1, 8))->mapWithKeys(fn ($i) => ["Link {$i}" => '/about'])->all();

    $this->actingAs($this->owner)->put('/admin/menus/header', menuLinks($links))->assertSessionHasErrors('items');
});

it('is only for staff who may write the public site', function () {
    $billing = User::factory()->admin()->create();
    $billing->permissions()->attach(Permission::query()->where('key', 'billing.view')->sole());

    $this->actingAs($billing->fresh())->get('/admin/menus')->assertForbidden();
    $this->actingAs($billing->fresh())->put('/admin/menus/header', menuLinks(['X' => '/about']))->assertForbidden();

    $this->actingAs($this->owner)->get('/admin/menus')->assertOk()->assertInertia(fn ($page) => $page
        ->component('admin/menus/Index')
        ->has('menus', 3)
        ->where('suggestions.0.href', '/'));
});

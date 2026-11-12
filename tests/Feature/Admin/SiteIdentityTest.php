<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();

    foreach ([
        'email' => 'hello@example.test',
        'phone' => '+91 98765 43210',
        'address' => '2nd floor, Example Tower',
        'city' => 'Lucknow',
        'state' => 'Uttar Pradesh',
        'postal_code' => '226010',
        'cin' => 'U72900UP2020PTC000000',
        'gstin' => '09AAAAA0000A1Z5',
        'pan' => 'AAAAA0000A',
    ] as $key => $value) {
        Setting::put("company.{$key}", $value, 'company');
    }
});

function siteDetails(array $overrides = []): array
{
    return [
        'show_email' => true,
        'show_phone' => true,
        'show_address' => true,
        'show_registration' => true,
        'whatsapp' => '+91 98765 43210',
        'social' => ['linkedin' => 'https://www.linkedin.com/company/example', 'github' => 'https://github.com/example'],
        ...$overrides,
    ];
}

it('shows no contact details on the public site until somebody chooses to', function () {
    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('site.legalName', 'Unboundbyte Solutions Private Limited')
        ->where('site.email', null)
        ->where('site.phone', null)
        ->where('site.address', null)
        ->where('site.whatsapp', null)
        ->where('site.registration', [])
        ->where('site.social', [])
        ->missing('seo.structuredData.email')
        ->missing('seo.structuredData.sameAs'));
});

it('shows what was switched on, on every public page and to search engines', function () {
    $this->actingAs($this->owner)->put('/admin/settings/site', siteDetails())->assertSessionHasNoErrors();

    $this->get('/contact')->assertInertia(fn ($page) => $page
        ->where('site.email', 'hello@example.test')
        ->where('site.phoneHref', 'tel:+919876543210')
        ->where('site.whatsapp', ['label' => '+91 98765 43210', 'href' => 'https://wa.me/919876543210'])
        ->where('site.address', "2nd floor, Example Tower\nLucknow, Uttar Pradesh, 226010")
        ->where('site.registration', ['CIN' => 'U72900UP2020PTC000000', 'GSTIN' => '09AAAAA0000A1Z5'])
        ->where('site.social.0', ['network' => 'linkedin', 'label' => 'LinkedIn', 'url' => 'https://www.linkedin.com/company/example']));

    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('seo.structuredData.email', 'hello@example.test')
        ->where('seo.structuredData.telephone', '+91 98765 43210')
        ->where('seo.structuredData.sameAs', ['https://www.linkedin.com/company/example', 'https://github.com/example']));

    $entry = AuditLog::query()->where('action', 'settings.site_updated')->sole();
    expect($entry->changes['show_email'])->toBe(['from' => false, 'to' => true]);
});

it('never shows the PAN field, whatever is switched on', function () {
    // A real GSTIN carries the PAN inside it (characters 3 to 12), so this
    // uses a PAN the GSTIN above does not contain, to see the field itself.
    Setting::put('company.pan', 'BBBBB1111B', 'company');
    $this->actingAs($this->owner)->put('/admin/settings/site', siteDetails());

    $this->get('/')->assertDontSee('BBBBB1111B', false);
    $this->get('/contact')->assertDontSee('BBBBB1111B', false);
});

it('keeps the GSTIN, and the PAN inside it, off the site unless registration is switched on', function () {
    $this->actingAs($this->owner)->put('/admin/settings/site', siteDetails(['show_registration' => false]));

    $this->get('/')->assertDontSee('09AAAAA0000A1Z5', false)->assertDontSee('AAAAA0000A', false);
});

it('turns an item off again', function () {
    $this->actingAs($this->owner)->put('/admin/settings/site', siteDetails());
    $this->actingAs($this->owner)->put('/admin/settings/site', siteDetails(['show_phone' => false, 'whatsapp' => '', 'social' => []]));

    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('site.phone', null)
        ->where('site.whatsapp', null)
        ->where('site.social', [])
        ->where('site.email', 'hello@example.test'));
});

it('only takes a profile on the network it is filed under', function (string $url) {
    $this->actingAs($this->owner)
        ->put('/admin/settings/site', siteDetails(['social' => ['linkedin' => $url]]))
        ->assertSessionHasErrors('social.linkedin');

    expect(Setting::get('site.social.linkedin'))->toBeNull();
})->with([
    'another site' => 'https://example.com/linkedin.com/company/x',
    'lookalike host' => 'https://linkedin.com.evil.test/x',
    'lookalike name' => 'https://evil-linkedin.com/x',
    'plain http' => 'http://www.linkedin.com/company/x',
    'script' => 'javascript:alert(1)',
]);

it('wants a WhatsApp number with its country code', function () {
    $this->actingAs($this->owner)
        ->put('/admin/settings/site', siteDetails(['whatsapp' => 'call me']))
        ->assertSessionHasErrors('whatsapp');
});

it('is changed only by staff who may change settings', function () {
    $viewer = User::factory()->admin()->create();
    $viewer->permissions()->attach(Permission::query()->where('key', 'settings.view')->sole());

    $this->actingAs($viewer->fresh())->get('/admin/settings')->assertOk()
        ->assertInertia(fn ($page) => $page->where('site.show_email', false)->has('socialNetworks', 6));
    $this->actingAs($viewer->fresh())->put('/admin/settings/site', siteDetails())->assertForbidden();
});

it('says whether realtime chat is wired to Pusher, never what the key is', function () {
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher.key' => 'pk_live_secret_value']);

    $response = $this->actingAs($this->owner)->get('/admin/settings')
        ->assertInertia(fn ($page) => $page->where('providers.4', ['name' => 'Realtime chat', 'configured' => true, 'detail' => 'Pusher']));

    $response->assertDontSee('pk_live_secret_value', false);
});

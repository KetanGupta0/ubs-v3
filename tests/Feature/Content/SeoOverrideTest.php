<?php

use App\Models\Permission;
use App\Models\SeoOverride;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

function overrideFor(string $path): SeoOverride
{
    SeoOverride::syncPages();

    return SeoOverride::query()->where('path', $path)->sole();
}

it('lists every fixed public page, each on its own wording until changed', function () {
    $this->actingAs($this->owner)->get('/admin/seo?per_page=50')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('table.rows', count(SeoOverride::PAGES))
            ->where('table.rows.0.state', 'Page default'));
});

it('changes what the about page tells search engines, without a deploy', function () {
    $default = null;
    $this->get('/about')->assertInertia(function ($page) use (&$default) {
        $default = $page->toArray()['props']['seo']['title'];
    });

    $about = overrideFor('/about');

    $this->actingAs($this->owner)->post("/admin/seo/{$about->id}", [
        'title' => 'About Unboundbyte: software and training from one team',
        'description' => 'Who we are, how we work, and why we teach the people who will run what we build.',
        'image' => UploadedFile::fake()->image('share.jpg', 1600, 840),
    ])->assertSessionHas('success');

    auth()->logout();

    $this->get('/about')->assertInertia(fn ($page) => $page
        ->where('seo.title', 'About Unboundbyte: software and training from one team')
        ->where('seo.description', 'Who we are, how we work, and why we teach the people who will run what we build.')
        ->where('seo.image', fn ($url) => str_contains($url, '/storage/seo/'))
        ->where('seo.noindex', false));

    expect($default)->not->toBe('About Unboundbyte: software and training from one team');
});

it('goes back to the page\'s own wording when the fields are emptied', function () {
    $about = overrideFor('/about');
    $about->update(['title' => 'Temporary title']);

    $this->actingAs($this->owner)->post("/admin/seo/{$about->id}", ['title' => '', 'description' => '']);

    $this->get('/about')->assertInertia(fn ($page) => $page->where('seo.title', fn ($title) => $title !== 'Temporary title'));
});

it('can keep a fixed page out of search engines', function () {
    $faq = overrideFor('/faq');

    $this->actingAs($this->owner)->post("/admin/seo/{$faq->id}", ['noindex' => true]);

    $this->get('/faq')->assertInertia(fn ($page) => $page->where('seo.noindex', true));
    $this->get('/about')->assertInertia(fn ($page) => $page->where('seo.noindex', false));
});

it('holds descriptions to what search engines show', function () {
    $this->actingAs($this->owner)->post('/admin/seo/'.overrideFor('/about')->id, [
        'description' => str_repeat('a', 159),
    ])->assertSessionHasErrors('description');
});

it('is only for staff who may write the public site', function () {
    $viewer = User::factory()->admin()->create();
    $viewer->permissions()->attach(Permission::query()->where('key', 'catalogue.manage')->sole());

    $this->actingAs($viewer->fresh())->get('/admin/seo')->assertForbidden();
});

<?php

use App\Models\Media;
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

function libraryPicture($test, string $alt = 'The training hall', string $name = 'hall.jpg', int $width = 2400): Media
{
    $test->actingAs($test->owner)
        ->post('/admin/media', ['alt' => $alt, 'file' => UploadedFile::fake()->image($name, $width, (int) ($width / 2))])
        ->assertSessionHasNoErrors();

    return Media::query()->latest('id')->first();
}

it('stores an upload re-encoded, capped in width, with its description', function () {
    $media = libraryPicture($this);

    expect($media->path)->toMatch(Media::PATH_PATTERN)
        ->and($media->width)->toBe(1600)
        ->and($media->height)->toBe(800)
        ->and($media->alt)->toBe('The training hall')
        ->and($media->uploaded_by)->toBe($this->owner->id);

    Storage::disk('public')->assertExists($media->path);
    expect(getimagesizefromstring(Storage::disk('public')->get($media->path))[0])->toBe(1600);
});

it('keeps a PNG a PNG, so a transparent logo stays transparent', function () {
    expect(libraryPicture($this, 'Our logo', 'logo.png', 400)->path)->toEndWith('.png');
});

it('needs a description and a real picture', function () {
    $this->actingAs($this->owner)
        ->post('/admin/media', ['alt' => '', 'file' => UploadedFile::fake()->image('a.jpg')])
        ->assertSessionHasErrors('alt');

    $this->actingAs($this->owner)
        ->post('/admin/media', ['alt' => 'A script', 'file' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')])
        ->assertSessionHasErrors('file');

    expect(Media::query()->count())->toBe(0);
});

it('lets a page use a library picture, and will not delete one a page shows', function () {
    $media = libraryPicture($this);

    $this->actingAs($this->owner)->post('/admin/pages', [
        'title' => 'Open day',
        'blocks' => [['type' => 'image', 'src' => $media->path, 'alt' => 'The hall on open day']],
    ])->assertSessionHasNoErrors();

    $page = Page::query()->sole();
    expect($page->blocks[0]['src'])->toBe($media->path)
        ->and($media->pages()->pluck('title')->all())->toBe(['Open day']);

    $this->actingAs($this->owner)->get('/admin/media')->assertInertia(fn ($p) => $p
        ->where('table.rows.0.used', 'Open day')
        ->where('summary.count', 1));

    $this->actingAs($this->owner)->delete("/admin/media/{$media->id}")->assertSessionHas('error');
    Storage::disk('public')->assertExists($media->path);

    // Off the page, it can go, file and all.
    $this->actingAs($this->owner)->post("/admin/pages/{$page->id}", ['title' => 'Open day', 'blocks' => []]);
    $this->actingAs($this->owner)->delete("/admin/media/{$media->id}")->assertSessionHas('success');
    Storage::disk('public')->assertMissing($media->path);
    expect(Media::query()->count())->toBe(0);
});

it('refuses a library-looking path that is not in the library', function () {
    $this->actingAs($this->owner)->post('/admin/pages', [
        'title' => 'Sneaky',
        'blocks' => [['type' => 'image', 'src' => 'media/00000000-0000-0000-0000-000000000000.jpg', 'alt' => 'x']],
    ])->assertSessionHasErrors('blocks.0.upload');
});

it('does not store an upload from a page that fails validation', function () {
    $this->actingAs($this->owner)->post('/admin/pages', [
        'title' => 'Half done',
        'blocks' => [
            ['type' => 'image', 'alt' => 'Hall', 'upload' => UploadedFile::fake()->image('hall.jpg', 800, 400)],
            ['type' => 'text', 'markdown' => ''],
        ],
    ])->assertSessionHasErrors('blocks.1.markdown');

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles('media'))->toBe([]);
});

it('changes a description, and serves the picker a page at a time', function () {
    $media = libraryPicture($this);

    $this->actingAs($this->owner)->put("/admin/media/{$media->id}", ['alt' => 'The hall, set for a workshop'])
        ->assertSessionHas('success');
    expect($media->fresh()->alt)->toBe('The hall, set for a workshop');

    $this->actingAs($this->owner)->getJson('/admin/media/picker')
        ->assertOk()
        ->assertJsonPath('items.0.path', $media->path)
        ->assertJsonPath('items.0.alt', 'The hall, set for a workshop')
        ->assertJsonPath('nextPage', null);
});

it('is only for staff who may write the public site', function () {
    $media = libraryPicture($this);

    $billing = User::factory()->admin()->create();
    $billing->permissions()->attach(Permission::query()->where('key', 'billing.view')->sole());

    $this->actingAs($billing->fresh())->get('/admin/media')->assertForbidden();
    $this->actingAs($billing->fresh())->getJson('/admin/media/picker')->assertForbidden();
    $this->actingAs($billing->fresh())->delete("/admin/media/{$media->id}")->assertForbidden();
});

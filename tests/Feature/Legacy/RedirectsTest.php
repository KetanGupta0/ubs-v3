<?php

use App\Models\Course;
use App\Models\Redirect;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyDatabase;

it('sends the old site\'s policy addresses to the new ones, permanently', function () {
    $this->get('/privacy-policy')->assertRedirect('/legal/privacy')->assertStatus(301);
    $this->get('/refund-policy?ref=mail')->assertRedirect('/legal/refunds?ref=mail');

    expect(Redirect::query()->where('from_path', '/privacy-policy')->value('hits'))->toBe(1);
});

it('still 404s an address nobody knows, and never shadows a real page', function () {
    $this->get('/no-such-page')->assertNotFound();
    // Only a page is redirected; anything else still gets its 404.
    $this->post('/privacy-policy')->assertNotFound();

    Redirect::query()->create(['from_path' => '/about', 'to_path' => '/contact']);
    $this->get('/about')->assertOk();
});

it('sends an old course address to the course it was mapped onto, and old enrol links with it', function () {
    LegacyDatabase::create();
    LegacyDatabase::createDelivery();
    Storage::fake('local');
    Course::query()->create(['title' => 'Full stack', 'slug' => 'full-stack', 'type' => 'course', 'tagline' => 't', 'summary' => 's']);
    LegacyDatabase::insert('training_programs', ['title' => 'Old full stack', 'slug' => 'old-full-stack']);

    $map = tempnam(sys_get_temp_dir(), 'map');
    file_put_contents($map, json_encode(['old-full-stack' => 'full-stack']));
    $this->artisan('legacy:import', ['--all-generation-one' => true, '--course-map' => $map])->assertSuccessful();
    unlink($map);

    $this->get('/training/old-full-stack')->assertRedirect('/training/full-stack')->assertStatus(301);
    $this->get('/enroll/old-full-stack')->assertRedirect('/enrol/full-stack');
});

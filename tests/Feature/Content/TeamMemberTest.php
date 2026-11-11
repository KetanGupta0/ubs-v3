<?php

use App\Models\Permission;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

function personPayload(array $overrides = []): array
{
    return [
        'name' => 'Asha Verma',
        'role' => 'Lead engineer',
        'bio' => 'Builds the platforms our clients run on.',
        'experience' => '9 years',
        'skills' => 'Laravel, Vue,  MySQL, laravel, ',
        'linkedin_url' => 'https://www.linkedin.com/in/asha-verma',
        'on_about' => true,
        'is_mentor' => true,
        'is_active' => true,
        'sort_order' => 1,
        ...$overrides,
    ];
}

it('shows nobody until somebody is added, and no placeholder faces', function () {
    $this->get('/about')->assertOk()->assertInertia(fn ($page) => $page->has('team', 0));
    $this->get('/training')->assertOk()->assertInertia(fn ($page) => $page->has('mentors', 0));
});

it('puts each person where they were asked to appear, in order, active ones only', function () {
    TeamMember::query()->create(['name' => 'Second', 'role' => 'Engineer', 'on_about' => true, 'is_mentor' => false, 'sort_order' => 2]);
    TeamMember::query()->create(['name' => 'First', 'role' => 'Director', 'on_about' => true, 'is_mentor' => true, 'sort_order' => 1, 'skills' => ['Strategy']]);
    TeamMember::query()->create(['name' => 'Mentor only', 'role' => 'Trainer', 'on_about' => false, 'is_mentor' => true, 'sort_order' => 3]);
    TeamMember::query()->create(['name' => 'Hidden', 'role' => 'Engineer', 'on_about' => true, 'is_mentor' => true, 'is_active' => false]);

    $this->get('/about')->assertInertia(fn ($page) => $page
        ->has('team', 2)
        ->where('team.0.name', 'First')
        ->where('team.1.name', 'Second')
        // Only what the card shows.
        ->has('team.0', fn ($person) => $person
            ->hasAll(['id', 'name', 'role', 'bio', 'experience', 'skills', 'photo', 'linkedin'])
            ->missing('is_active')
            ->missing('sort_order')));

    $this->get('/training')->assertInertia(fn ($page) => $page
        ->has('mentors', 2)
        ->where('mentors.0.name', 'First')
        ->where('mentors.0.skills', ['Strategy'])
        ->where('mentors.1.name', 'Mentor only'));
});

it('adds a person with a re-encoded photo and a tidy list of skills', function () {
    $this->actingAs($this->owner)
        ->post('/admin/team', personPayload(['photo' => UploadedFile::fake()->image('asha.png', 1600, 1600)]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $person = TeamMember::query()->sole();

    expect($person->skills)->toBe(['Laravel', 'Vue', 'MySQL'])
        ->and($person->photo_path)->toMatch('#^team/[0-9a-f-]{36}\.jpg$#');

    Storage::disk('public')->assertExists($person->photo_path);
    expect(getimagesizefromstring(Storage::disk('public')->get($person->photo_path))[0])->toBe(800);

    $this->actingAs($this->owner)->get("/admin/team/{$person->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/team/Form')->where('member.skills', 'Laravel, Vue, MySQL'));
});

it('accepts only a LinkedIn address for LinkedIn', function (string $url) {
    $this->actingAs($this->owner)
        ->post('/admin/team', personPayload(['linkedin_url' => $url]))
        ->assertSessionHasErrors('linkedin_url');

    expect(TeamMember::query()->count())->toBe(0);
})->with([
    'script' => 'javascript:alert(1)',
    'another site' => 'https://example.com/linkedin.com/in/x',
    'plain http' => 'http://www.linkedin.com/in/x',
]);

it('needs somewhere to show a person', function () {
    $this->actingAs($this->owner)
        ->post('/admin/team', personPayload(['on_about' => false, 'is_mentor' => false]))
        ->assertSessionHasErrors('on_about');
});

it('deletes a photo for real when it is replaced, removed, or the person goes', function () {
    $this->actingAs($this->owner)->post('/admin/team', personPayload(['photo' => UploadedFile::fake()->image('a.jpg', 400, 400)]));
    $person = TeamMember::query()->sole();
    $first = $person->photo_path;

    $this->actingAs($this->owner)->post("/admin/team/{$person->id}", personPayload(['photo' => UploadedFile::fake()->image('b.jpg', 400, 400)]))
        ->assertSessionHasNoErrors();
    $second = $person->fresh()->photo_path;
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    $this->actingAs($this->owner)->post("/admin/team/{$person->id}", personPayload(['remove_photo' => true]));
    expect($person->fresh()->photo_path)->toBeNull();
    Storage::disk('public')->assertMissing($second);

    $this->actingAs($this->owner)->post("/admin/team/{$person->id}", personPayload(['photo' => UploadedFile::fake()->image('c.jpg', 400, 400)]));
    $third = $person->fresh()->photo_path;
    $this->actingAs($this->owner)->delete("/admin/team/{$person->id}")->assertRedirect('/admin/team');

    Storage::disk('public')->assertMissing($third);
    expect(TeamMember::query()->count())->toBe(0);
});

it('is only for staff who may write the public site', function () {
    $person = TeamMember::query()->create(['name' => 'X', 'role' => 'Y']);

    $billing = User::factory()->admin()->create();
    $billing->permissions()->attach(Permission::query()->where('key', 'billing.view')->sole());
    $this->actingAs($billing->fresh())->get('/admin/team')->assertForbidden();
    $this->actingAs($billing->fresh())->delete("/admin/team/{$person->id}")->assertForbidden();

    $writer = User::factory()->admin()->create();
    $writer->permissions()->attach(Permission::query()->where('key', 'content.manage')->sole());
    $this->actingAs($writer->fresh())->get('/admin/team')->assertOk()
        ->assertInertia(fn ($page) => $page->where('table.rows.0.shown', 'About'));
    $this->actingAs($writer->fresh())->get("/admin/team/{$person->id}/edit")->assertOk();
});

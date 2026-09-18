<?php

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lead;
use App\Models\PaymentRequest;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Billing\Invoicer;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.prefix', 'UBS', 'invoicing');

    $this->owner = User::factory()->owner()->create();
    $this->client = User::factory()->client()->create(['name' => 'Meridian Logistics']);
    $this->stranger = User::factory()->client()->create(['name' => 'Northwind Traders']);

    $this->project = Project::query()->create([
        'client_id' => $this->client->id,
        'name' => 'Warehouse management system',
        'status' => 'in_progress',
    ]);

    $this->theirs = Project::query()->create([
        'client_id' => $this->stranger->id,
        'name' => 'Warehouse robotics pilot',
        'status' => 'in_progress',
    ]);
});

function results(array $body): array
{
    return collect($body['groups'] ?? [])->flatMap(fn (array $group) => $group['results'])->all();
}

it('finds people, projects and invoices for an administrator', function () {
    $body = $this->actingAs($this->owner)->getJson('/search?q=Warehouse')->assertOk()->json();

    expect(collect(results($body))->pluck('title'))
        ->toContain('Warehouse management system')
        ->toContain('Warehouse robotics pilot');
});

it('shows a client only their own records', function () {
    $body = $this->actingAs($this->client)->getJson('/search?q=Warehouse')->assertOk()->json();
    $titles = collect(results($body))->pluck('title');

    expect($titles)->toContain('Warehouse management system')
        ->and($titles)->not->toContain('Warehouse robotics pilot');
});

it('shows a student only the courses they are on', function () {
    $student = User::factory()->student()->create();

    $mine = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'search-mine-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks.',
    ]);

    Course::query()->create([
        'title' => 'Web design for beginners',
        'slug' => 'search-theirs-'.uniqid(),
        'type' => 'course',
        'tagline' => 'Not mine',
        'summary' => 'Six weeks.',
    ]);

    Enrollment::query()->create([
        'user_id' => $student->id,
        'course_id' => $mine->id,
        'enrolled_at' => now(),
        'has_paid' => true,
    ]);

    $titles = collect(results($this->actingAs($student)->getJson('/search?q=Web')->json()))->pluck('title');

    expect($titles)->toContain('Web development internship')
        ->and($titles)->not->toContain('Web design for beginners');
});

it('respects permissions inside the admin panel', function () {
    $staff = User::factory()->create(['role' => Role::Admin]);
    $staff->permissions()->sync(Permission::query()->where('key', 'students.view')->pluck('id'));

    $body = $this->actingAs($staff)->getJson('/search?q=Warehouse')->json();

    expect(collect($body['groups'])->pluck('label'))->not->toContain('Projects');
});

it('treats a wildcard somebody typed as a character, not an operator', function () {
    // '%' matches everything in a LIKE. Escaped, it matches a literal percent,
    // which is what the person typing it meant.
    $body = $this->actingAs($this->owner)->getJson('/search?q=%')->json();

    expect(results($body))->toBeEmpty();

    $noisy = $this->actingAs($this->owner)->getJson('/search?q='.urlencode('%are%'))->json();

    expect(results($noisy))->toBeEmpty();
});

it('says nothing for one character', function () {
    $body = $this->actingAs($this->owner)->getJson('/search?q=W')->json();

    expect($body['groups'])->toBeEmpty();
});

it('finds an invoice by its number', function () {
    $request = PaymentRequest::query()->create(['user_id' => $this->client->id, 'title' => 'Milestone']);
    $request->price(Money::toPaise(50000), 18)->save();

    $invoice = app(Invoicer::class)->issueFor($request);

    $titles = collect(results($this->actingAs($this->owner)->getJson('/search?q='.$invoice->number)->json()))
        ->pluck('title');

    expect($titles)->toContain($invoice->number);
});

it('turns away somebody who is not signed in', function () {
    $this->getJson('/search?q=Warehouse')->assertUnauthorized();
});

/**
 * The one that matters most: a search result is a link, and a link nobody
 * clicked in a test is a link nobody tested. Every href the search can produce
 * is opened here, because a 404 from the search box is worse than no search.
 */
it('produces links that actually open', function () {
    $student = User::factory()->student()->create(['name' => 'Warehouse Student']);

    $course = Course::query()->create([
        'title' => 'Warehouse systems',
        'slug' => 'search-links-'.uniqid(),
        'type' => 'course',
        'tagline' => 'Build one',
        'summary' => 'Six weeks.',
    ]);

    Batch::query()->create([
        'course_id' => $course->id,
        'name' => 'Warehouse batch',
        'code' => 'WH-'.uniqid(),
        'starts_on' => today(),
    ]);

    Lead::query()->create([
        'name' => 'Warehouse enquiry',
        'email' => 'warehouse@example.test',
        'interest' => 'solution',
        'message' => 'Do you build warehouse systems?',
    ]);

    SupportTicket::query()->create([
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'subject' => 'Warehouse scanner bug',
        'body' => 'It beeps twice.',
        'priority' => 'normal',
    ]);

    $request = PaymentRequest::query()->create(['user_id' => $this->client->id, 'title' => 'Warehouse milestone']);
    $request->price(Money::toPaise(50000), 18)->save();
    app(Invoicer::class)->issueFor($request);

    $links = collect(results($this->actingAs($this->owner)->getJson('/search?q=Warehouse')->json()))
        ->pluck('href')
        ->unique();

    expect($links)->not->toBeEmpty();

    foreach ($links as $href) {
        $this->actingAs($this->owner)->get($href)->assertOk();
    }
});

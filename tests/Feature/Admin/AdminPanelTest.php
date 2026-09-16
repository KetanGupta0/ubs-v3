<?php

use App\Models\College;
use App\Models\Faq;
use App\Models\Setting;
use App\Models\Testimonial;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
});

/* ----------------------------------------------------------------- colleges */

it('adds a college and derives its address in links', function () {
    $this->actingAs($this->owner)->post('/admin/colleges', [
        'name' => 'Government Engineering College, Bhopal',
        'city' => 'Bhopal',
        'state' => 'Madhya Pradesh',
    ])->assertRedirect('/admin/colleges');

    expect(College::query()->sole()->slug)->toBe('government-engineering-college-bhopal');
});

it('refuses a memorandum that expires before it was signed', function () {
    $this->actingAs($this->owner)->post('/admin/colleges', [
        'name' => 'Backwards College',
        'mou_signed_on' => '2026-06-01',
        'mou_expires_on' => '2026-01-01',
    ])->assertSessionHasErrors('mou_expires_on');
});

it('will not orphan students by removing their college', function () {
    $college = College::query()->create(['name' => 'Linked College', 'slug' => 'linked-college']);
    $student = User::factory()->student()->create();
    $student->profile()->create(['college_id' => $college->id]);

    $this->actingAs($this->owner)
        ->delete("/admin/colleges/{$college->slug}")
        ->assertSessionHasErrors('college');

    expect(College::query()->count())->toBe(1);
});

/* ------------------------------------------------------------------ content */

it('keeps a new testimonial off the site until it is published', function () {
    $this->actingAs($this->owner)->post('/admin/content/testimonials', [
        'author_name' => 'Anita Rao',
        'quote' => 'They shipped on the day they said they would.',
    ])->assertRedirect();

    expect(Testimonial::query()->sole()->is_published)->toBeFalse();
});

it('shows an unpublished question to an administrator and nobody else', function () {
    Faq::query()->create([
        'question' => 'Do you work weekends?',
        'answer' => 'Only when a release needs it.',
        'group' => 'Working with us',
        'is_published' => false,
    ]);

    $this->actingAs($this->owner)->get('/admin/content')
        ->assertInertia(fn ($page) => $page->has('faqs', 1));

    $this->get('/faq')->assertDontSee('Do you work weekends?');
});

/* ----------------------------------------------------------------- settings */

it('saves company details and reads them back', function () {
    $this->actingAs($this->owner)->put('/admin/settings/company', [
        'name' => 'Unboundbyte Solutions',
        'legal_name' => 'Unboundbyte Solutions Private Limited',
        'city' => 'Bhopal',
    ])->assertRedirect();

    expect(Setting::get('company.city'))->toBe('Bhopal');
});

it('never sends a provider credential to the browser', function () {
    config([
        'services.google.client_secret' => 'a-real-google-secret',
        'services.razorpay.key_secret' => 'a-real-razorpay-secret',
    ]);

    // Keys belong in the environment. A settings screen that displays one, even
    // masked, is one backup or one shoulder away from leaking it.
    $response = $this->actingAs($this->owner)->get('/admin/settings');

    $response->assertOk()
        ->assertDontSee('a-real-google-secret')
        ->assertDontSee('a-real-razorpay-secret');
});

it('refuses an invoice prefix that is not a prefix', function () {
    $this->actingAs($this->owner)->put('/admin/settings/invoicing', [
        'prefix' => 'ubs invoices!',
        'next_number' => 1,
        'financial_year_start_month' => 4,
        'default_tax_rate' => 18,
    ])->assertSessionHasErrors('prefix');
});

/* ---------------------------------------------------------------- dashboard */

it('renders every admin screen, with rows in it', function () {
    /*
     * The rows matter. An index with nothing in it never eager loads, so a
     * broken relation on the row query looks fine until the first real record
     * arrives; that is exactly how the missing college relation got through.
     */
    $college = College::query()->create(['name' => 'A College', 'slug' => 'a-college']);

    $client = User::factory()->client()->create();
    $client->profile()->create(['company' => 'Meridian Logistics']);

    $student = User::factory()->student()->create();
    $student->profile()->create(['college_id' => $college->id, 'enrollment_number' => '0101CS221001']);

    $paths = [
        '/admin', '/admin/leads', '/admin/clients', '/admin/students',
        "/admin/clients/{$client->id}", "/admin/students/{$student->id}",
        '/admin/clients/new', '/admin/students/new',
        '/admin/colleges', '/admin/colleges/new', "/admin/colleges/{$college->slug}/edit",
        '/admin/solutions', '/admin/services', '/admin/courses', '/admin/batches',
        '/admin/content', '/admin/staff', '/admin/settings', '/admin/audit-log',
    ];

    foreach ($paths as $path) {
        $this->actingAs($this->owner)->get($path)->assertOk();
    }
});

it('shows which college a student came in through', function () {
    $college = College::query()->create(['name' => 'Linked College', 'slug' => 'linked-college']);
    $student = User::factory()->student()->create();
    $student->profile()->create(['college_id' => $college->id]);

    $this->actingAs($this->owner)->get('/admin/students')
        ->assertInertia(fn ($page) => $page->where('table.rows.0.organisation', 'Linked College'));
});

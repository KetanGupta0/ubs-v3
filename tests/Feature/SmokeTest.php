<?php

use App\Models\User;

it('renders the public landing page', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Welcome'));
});

it('renders the design system gallery outside production', function () {
    User::factory()->count(3)->create();

    $this->get('/design')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Design/Index')
            ->has('table.rows', 3)
            ->has('table.columns')
            ->has('table.filters'),
        );
});

it('exports the table as CSV with the filters applied', function () {
    User::factory()->create(['name' => 'Aarti Sharma']);
    User::factory()->create(['name' => 'Rahul Verma']);

    $response = $this->get('/design?export=csv&q=aarti');

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $body = $response->streamedContent();

    expect($body)->toContain('Aarti Sharma')->not->toContain('Rahul Verma');
});

it('exports the table as a PDF', function () {
    User::factory()->count(2)->create();

    $response = $this->get('/design?export=pdf');

    $response->assertOk();

    // DomPDF returns a buffered response rather than a stream.
    expect($response->getContent())->toStartWith('%PDF-');
});

it('rejects an unknown export format', function () {
    $this->get('/design?export=exe')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Design/Index'));
});

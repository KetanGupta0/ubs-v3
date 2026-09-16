<?php

use App\Models\Lead;
use App\Models\Solution;
use App\Models\SolutionCategory;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\LeadAcknowledgement;
use App\Notifications\LeadReceived;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $category = SolutionCategory::query()->create(['name' => 'Ops', 'slug' => 'ops']);

    $this->solution = Solution::query()->create([
        'solution_category_id' => $category->id,
        'title' => 'Unified ERP suite', 'slug' => 'unified-erp-suite',
        'tagline' => 't', 'summary' => 's', 'is_published' => true,
    ]);

    $this->payload = [
        'name' => 'Aarti Sharma',
        'email' => 'Aarti@Example.COM',
        'mobile' => '98765 43210',
        'company' => 'Meridian Logistics',
        'message' => 'We run four factories and everything is in spreadsheets.',
        'interest' => 'solution',
        'solution_slug' => 'unified-erp-suite',
        'budget_band' => '₹10L – ₹25L',
        'source_page' => '/solutions/unified-erp-suite',
    ];
});

it('records an enquiry with the context it came from', function () {
    $this->post('/enquiries', $this->payload)->assertRedirect();

    $lead = Lead::query()->first();

    expect($lead)->not->toBeNull()
        ->and($lead->name)->toBe('Aarti Sharma')
        // Normalised on the way in, so the inbox is consistent.
        ->and($lead->email)->toBe('aarti@example.com')
        ->and($lead->mobile)->toBe('+919876543210')
        ->and($lead->solution_id)->toBe($this->solution->id)
        ->and($lead->source_page)->toBe('/solutions/unified-erp-suite')
        ->and($lead->status)->toBe('new')
        ->and($lead->subject())->toBe('Solution: Unified ERP suite');
});

it('gives each enquiry a readable reference', function () {
    $this->post('/enquiries', $this->payload);
    $this->post('/enquiries', [...$this->payload, 'email' => 'second@example.com']);

    $references = Lead::query()->orderBy('id')->pluck('reference');

    expect($references[0])->toMatch('/^UBS-\d{4}-0001$/')
        ->and($references[1])->toEndWith('-0002');
});

it('returns the reference to the browser so it can be shown once', function () {
    $this->post('/enquiries', $this->payload)
        ->assertSessionHas('leadReference')
        ->assertSessionHas('success');
});

it('notifies the team and confirms to the sender', function () {
    $admin = User::factory()->admin()->create(['mobile' => '+919000000001']);

    $this->post('/enquiries', $this->payload);

    Notification::assertSentTo($admin, LeadReceived::class);
    Notification::assertSentOnDemand(LeadAcknowledgement::class);
});

it('sends the team notification by SMS as well when a number is on file', function () {
    $admin = User::factory()->admin()->create(['mobile' => '+919000000001']);

    $this->post('/enquiries', $this->payload);

    Notification::assertSentTo($admin, LeadReceived::class, function (LeadReceived $notification, array $channels) {
        return in_array(SmsChannel::class, $channels, true);
    });
});

it('does not notify a suspended administrator', function () {
    $suspended = User::factory()->admin()->suspended()->create();

    $this->post('/enquiries', $this->payload);

    Notification::assertNotSentTo($suspended, LeadReceived::class);
});

it('rejects a submission that fills the hidden field', function () {
    // A person never sees this field. A crude bot fills everything it finds.
    $this->post('/enquiries', [...$this->payload, 'website' => 'http://spam.example'])
        ->assertInvalid('website');

    expect(Lead::query()->count())->toBe(0);
});

it('requires a message long enough to be worth replying to', function () {
    $this->post('/enquiries', [...$this->payload, 'message' => 'hi'])->assertInvalid('message');

    expect(Lead::query()->count())->toBe(0);
});

it('rejects an invalid mobile number', function () {
    $this->post('/enquiries', [...$this->payload, 'mobile' => '12'])->assertInvalid('mobile');
});

it('accepts an enquiry with no mobile number', function () {
    $this->post('/enquiries', [...$this->payload, 'mobile' => null])->assertRedirect();

    expect(Lead::query()->first()->mobile)->toBeNull();
});

it('refuses a solution slug that does not exist', function () {
    $this->post('/enquiries', [...$this->payload, 'solution_slug' => 'made-up'])
        ->assertInvalid('solution_slug');
});

it('throttles repeated submissions', function () {
    foreach (range(1, 6) as $i) {
        $this->post('/enquiries', [...$this->payload, 'email' => "sender{$i}@example.com"]);
    }

    // The endpoint writes a row and sends two messages on every success.
    $this->post('/enquiries', [...$this->payload, 'email' => 'seventh@example.com'])
        ->assertStatus(429);
});

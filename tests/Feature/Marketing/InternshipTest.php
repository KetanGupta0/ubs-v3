<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadReceived;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->internship = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'web-development-internship',
        'type' => 'internship',
        'tagline' => 'Build and deploy a real web application',
        'summary' => 'Six weeks building a working web application.',
        'level' => 'beginner',
        'duration_weeks' => 6,
        'hours_per_week' => 12,
        'price' => 6000,
        'sale_price' => 4500,
        'mode' => 'remote',
        'project_focus' => 'A multi page web application with a database behind it',
        'documents_provided' => [
            ['title' => 'Offer letter', 'body' => 'Before you start.'],
            ['title' => 'Completion certificate', 'body' => 'With a verification link.'],
            ['title' => 'Project report', 'body' => 'Ready to submit.'],
            ['title' => 'Mentor evaluation', 'body' => 'Signed, with marks.'],
        ],
        'visibility' => 'public',
        'is_published' => true,
        'is_featured' => true,
    ]);

    $this->course = Course::query()->create([
        'title' => 'Full stack web development',
        'slug' => 'full-stack-web-development',
        'type' => 'programme',
        'tagline' => 'From first line of HTML to a deployed application',
        'summary' => 'A six month live programme.',
        'level' => 'beginner',
        'duration_weeks' => 24,
        'price' => 45000,
        'visibility' => 'public',
        'is_published' => true,
        'is_featured' => true,
    ]);

    $this->private = Course::query()->create([
        'title' => 'Internal track', 'slug' => 'internal-track', 'type' => 'internship',
        'tagline' => 't', 'summary' => 's', 'level' => 'beginner',
        'visibility' => 'lms_only', 'is_published' => true,
    ]);
});

it('lists internships separately from courses', function () {
    $this->get('/internships')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/internships/Index')
            ->has('internships', 1)
            ->where('internships.0.slug', 'web-development-internship'),
        );

    $this->get('/training')
        ->assertInertia(fn ($page) => $page
            ->has('courses', 1)
            ->where('courses.0.slug', 'full-stack-web-development'),
        );
});

it('keeps the two listings from bleeding into each other', function () {
    // They share a table, so each route has to check what the row actually is.
    $this->get('/training/web-development-internship')->assertNotFound();
    $this->get('/internships/full-stack-web-development')->assertNotFound();
});

it('refuses an internship that is LMS only', function () {
    $this->get('/internships/internal-track')->assertNotFound();
});

it('shows the four documents a college asks for', function () {
    $this->get('/internships/web-development-internship')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/internships/Show')
            ->has('internship.documents', 4)
            ->where('internship.documents.0.title', 'Offer letter')
            ->where('internship.documents.3.title', 'Mentor evaluation')
            ->where('internship.projectFocus', 'A multi page web application with a database behind it'),
        );
});

it('labels duration by month when the internship is sold that way', function () {
    $this->internship->update(['duration_weeks' => 24, 'duration_months' => 6]);

    expect($this->internship->fresh()->durationLabel())->toBe('6 months');

    $this->internship->update(['duration_months' => null, 'duration_weeks' => 6]);

    expect($this->internship->fresh()->durationLabel())->toBe('6 weeks');
});

it('filters internships by duration', function () {
    Course::query()->create([
        'title' => 'Six month', 'slug' => 'six-month', 'type' => 'internship',
        'tagline' => 't', 'summary' => 's', 'level' => 'beginner',
        'duration_weeks' => 24, 'duration_months' => 6, 'price' => 24000,
        'visibility' => 'public', 'is_published' => true,
    ]);

    $this->get('/internships?duration=short')
        ->assertInertia(fn ($page) => $page->has('internships', 1)->where('internships.0.slug', 'web-development-internship'));

    $this->get('/internships?duration=long')
        ->assertInertia(fn ($page) => $page->has('internships', 1)->where('internships.0.slug', 'six-month'));
});

it('renders the page a college is sent to', function () {
    $this->get('/for-colleges')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/ForColleges')
            ->has('internships', 1),
        );
});

it('records an internship application against the internship', function () {
    Notification::fake();

    $this->post('/enquiries', [
        'name' => 'Rahul Verma',
        'email' => 'rahul@example.com',
        'mobile' => '98765 43210',
        'message' => 'I am in my third year of BTech and my college requires a six week internship.',
        'interest' => 'internship',
        'course_slug' => 'web-development-internship',
    ])->assertRedirect();

    $lead = Lead::query()->first();

    expect($lead->interest)->toBe('internship')
        ->and($lead->course_id)->toBe($this->internship->id)
        // The label comes from what the offering is, not the table it lives in.
        ->and($lead->subject())->toBe('Internship: Web development internship');
});

it('records a college tie-up enquiry with the institution and headcount', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();

    $this->post('/enquiries', [
        'name' => 'Dr Anita Rao',
        'email' => 'tpo@college.example',
        'message' => 'We want to place forty final year students on a six month internship.',
        'interest' => 'college',
        'college_name' => 'Government Engineering College',
        'student_count' => 40,
    ])->assertRedirect();

    $lead = Lead::query()->first();

    expect($lead->college_name)->toBe('Government Engineering College')
        ->and($lead->student_count)->toBe(40)
        ->and($lead->subject())->toBe('College tie-up: Government Engineering College');

    Notification::assertSentTo($admin, LeadReceived::class);
});

it('rejects an implausible student count', function () {
    $this->post('/enquiries', [
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'message' => 'We would like to send some students on an internship please.',
        'interest' => 'college',
        'college_name' => 'A college',
        'student_count' => 99999,
    ])->assertInvalid('student_count');
});

it('lists internships and courses under their own paths in the sitemap', function () {
    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('/internships/web-development-internship')
        ->and($xml)->toContain('/training/full-stack-web-development')
        ->and($xml)->toContain('/for-colleges')
        // The internship must not also appear under the training path.
        ->and($xml)->not->toContain('/training/web-development-internship')
        ->and($xml)->not->toContain('internal-track');
});

it('counts internships and courses separately on the home page', function () {
    $this->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('stats.internships', 1)
            ->where('stats.courses', 1)
            ->has('featuredInternships', 1)
            ->has('featuredCourses', 1),
        );
});

it('shows upcoming batches with seats left', function () {
    Batch::query()->create([
        'course_id' => $this->internship->id, 'name' => 'October batch', 'code' => 'WEB-OCT',
        'starts_on' => now()->addWeeks(2), 'schedule' => [['day' => 'Sat', 'from' => '10:00', 'to' => '13:00']],
        'capacity' => 24, 'seats_taken' => 21, 'status' => 'upcoming', 'is_published' => true,
    ]);

    $this->get('/internships/web-development-internship')
        ->assertInertia(fn ($page) => $page
            ->has('batches', 1)
            ->where('batches.0.seatsLeft', 3)
            ->where('batches.0.nearlyFull', true),
        );
});

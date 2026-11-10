<?php

use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Testimonial;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create(['name' => 'Asha Verma']);

    $this->course = Course::query()->create([
        'title' => 'Python and data internship', 'slug' => 'reviewed-'.uniqid(), 'type' => 'internship',
        'tagline' => 'x', 'summary' => 'y', 'price' => 0, 'visibility' => 'public', 'is_published' => true,
    ]);
    $this->enrolment = Enrollment::query()->create([
        'user_id' => $this->student->id, 'course_id' => $this->course->id,
        'enrolled_at' => now(), 'has_paid' => true,
    ]);
});

function writeReview($test, array $payload = [])
{
    return $test->actingAs($test->student)->post("/student/courses/{$test->course->id}/review", [
        'rating' => 5,
        'title' => 'Worth every week',
        'body' => 'The mentor reviewed my code every Friday and I learnt more than in a semester.',
        'may_publish' => true,
        ...$payload,
    ]);
}

it('takes a review from a student on the course, waiting to be read', function () {
    writeReview($this)->assertSessionHas('success');

    $review = CourseReview::query()->sole();

    expect($review->status)->toBe('pending')
        ->and($review->may_publish)->toBeTrue()
        ->and($review->enrollment_id)->toBe($this->enrolment->id);

    $this->actingAs($this->student)->get("/student/courses/{$this->course->id}")
        ->assertInertia(fn ($page) => $page->where('review.status', 'pending'));
});

it('refuses a review from somebody not on the course', function () {
    $stranger = User::factory()->student()->create();

    $this->actingAs($stranger)->post("/student/courses/{$this->course->id}/review", [
        'rating' => 1, 'body' => 'Never took it but here is my opinion anyway.',
    ])->assertNotFound();

    expect(CourseReview::query()->count())->toBe(0);
});

it('shows nothing on the public page until a review is approved, and then only with consent', function () {
    writeReview($this);
    $public = fn () => $this->get("/internships/{$this->course->slug}")->assertOk();

    $public()->assertInertia(fn ($page) => $page->where('reviews.count', 0));

    $review = CourseReview::query()->sole();
    $this->actingAs($this->owner)->put("/admin/reviews/{$review->id}/status", ['status' => 'approved']);
    auth()->logout();

    $public()->assertInertia(fn ($page) => $page
        ->where('reviews.count', 1)
        ->where('reviews.average', 5)
        // First name and initial: a real person, not a findable one.
        ->where('reviews.items.0.name', 'Asha V.'));

    // Without consent, approval keeps it among students only.
    $review->forceFill(['may_publish' => false])->save();
    $public()->assertInertia(fn ($page) => $page->where('reviews.count', 0));
});

it('sends an edited review back to be read again', function () {
    writeReview($this);
    $review = CourseReview::query()->sole();
    $this->actingAs($this->owner)->put("/admin/reviews/{$review->id}/status", ['status' => 'approved']);

    writeReview($this, ['rating' => 2, 'body' => 'Changed my mind once the second month started.']);

    expect($review->fresh()->status)->toBe('pending')
        ->and($review->fresh()->rating)->toBe(2)
        ->and(CourseReview::query()->count())->toBe(1);
});

it('lets staff reply, and turns a consented review into a draft testimonial', function () {
    writeReview($this);
    $review = CourseReview::query()->sole();

    $this->actingAs($this->owner)->put("/admin/reviews/{$review->id}/reply", ['reply' => 'Thank you, Asha.'])
        ->assertSessionHas('success');

    // Not approved yet: no testimonial.
    $this->actingAs($this->owner)->post("/admin/reviews/{$review->id}/testimonial")->assertSessionHas('error');

    $this->actingAs($this->owner)->put("/admin/reviews/{$review->id}/status", ['status' => 'approved']);
    $this->actingAs($this->owner)->post("/admin/reviews/{$review->id}/testimonial")->assertSessionHas('success');

    $testimonial = Testimonial::query()->sole();

    expect($review->fresh()->reply)->toBe('Thank you, Asha.')
        ->and($testimonial->author_name)->toBe('Asha V.')
        ->and($testimonial->is_published)->toBeFalse();
});

it('will not make a testimonial of a review whose author did not agree', function () {
    writeReview($this, ['may_publish' => false]);
    $review = CourseReview::query()->sole();
    $this->actingAs($this->owner)->put("/admin/reviews/{$review->id}/status", ['status' => 'approved']);

    $this->actingAs($this->owner)->post("/admin/reviews/{$review->id}/testimonial")->assertSessionHas('error');

    expect(Testimonial::query()->count())->toBe(0);
});

it('lists reviews for staff who can see the catalogue, and lets only managers moderate', function () {
    writeReview($this);
    $review = CourseReview::query()->sole();

    $viewer = User::factory()->admin()->create();
    $viewer->permissions()->attach(Permission::query()->where('key', 'catalogue.view')->sole());

    $this->actingAs($viewer->fresh())->get('/admin/reviews?filter[status]=pending')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('table.rows', 1)->where('canModerate', false));

    $this->actingAs($viewer->fresh())->put("/admin/reviews/{$review->id}/status", ['status' => 'approved'])->assertForbidden();
});

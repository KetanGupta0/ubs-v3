<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LiveSession;
use App\Models\User;

beforeEach(function () {
    $this->student = User::factory()->student()->create();

    $this->course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'api-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'September batch',
        'code' => 'API-'.uniqid(),
        'starts_on' => today()->subWeek(),
        'meet_link' => 'https://meet.google.com/abc-defg-hij',
    ]);

    $this->enrolment = Enrollment::query()->create([
        'user_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeek(),
        'has_paid' => true,
    ]);

    $module = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Week one']);

    $this->lesson = Lesson::query()->create([
        'course_id' => $this->course->id,
        'course_module_id' => $module->id,
        'title' => 'Request to response',
        'slug' => 'api-lesson-'.uniqid(),
        'content' => 'What actually happens between a click and a page.',
    ]);

    $this->locked = Lesson::query()->create([
        'course_id' => $this->course->id,
        'course_module_id' => $module->id,
        'title' => 'Much later',
        'slug' => 'api-locked-'.uniqid(),
        'content' => 'Not yet.',
        'unlock_after_days' => 90,
    ]);
});

function studentToken(User $user): string
{
    return $user->createToken('phone', ['*'])->plainTextToken;
}

it('answers the overview for a signed in student', function () {
    LiveSession::query()->create([
        'batch_id' => $this->batch->id,
        'title' => 'Session five',
        'scheduled_at' => now()->addDays(2),
    ]);

    $this->withToken(studentToken($this->student))
        ->getJson('/api/v1/student/overview')
        ->assertOk()
        ->assertJsonPath('totals.courses', 1)
        ->assertJsonPath('nextClass.title', 'Session five')
        ->assertJsonPath('courses.0.title', 'Web development internship');
});

it('serves a lesson this student may open', function () {
    $this->withToken(studentToken($this->student))
        ->getJson("/api/v1/student/courses/{$this->course->id}/lessons/{$this->lesson->id}")
        ->assertOk()
        ->assertJsonPath('data.lock.open', true)
        ->assertJsonPath('data.content', 'What actually happens between a click and a page.');
});

it('answers a sealed lesson with its lock rather than its content', function () {
    $response = $this->withToken(studentToken($this->student))
        ->getJson("/api/v1/student/courses/{$this->course->id}/lessons/{$this->locked->id}")
        ->assertStatus(423)
        ->assertJsonPath('data.lock.open', false);

    expect($response->json('data'))->not->toHaveKey('content');
});

it('refuses to mark a sealed lesson finished', function () {
    $this->withToken(studentToken($this->student))
        ->postJson("/api/v1/student/courses/{$this->course->id}/lessons/{$this->locked->id}/complete")
        ->assertForbidden();

    expect($this->locked->completions()->count())->toBe(0);
});

it('sends the join link only once the class has opened', function () {
    LiveSession::query()->create([
        'batch_id' => $this->batch->id,
        'title' => 'Tomorrow',
        'scheduled_at' => now()->addDay(),
        'meet_link' => 'https://meet.google.com/secret-link',
    ]);

    $body = $this->withToken(studentToken($this->student))
        ->getJson('/api/v1/student/classes')
        ->assertOk()
        ->json();

    expect($body['upcoming'][0]['joinable'])->toBeFalse()
        ->and($body['upcoming'][0]['joinUrl'])->toBeNull()
        ->and(json_encode($body))->not->toContain('secret-link');
});

it('does not serve a course this student is not on', function () {
    $stranger = User::factory()->student()->create();

    $this->withToken(studentToken($stranger))
        ->getJson("/api/v1/student/courses/{$this->course->id}")
        ->assertNotFound();
});

it('keeps a client out of the student API', function () {
    $this->withToken(studentToken(User::factory()->client()->create()))
        ->getJson('/api/v1/student/courses')
        ->assertForbidden();
});

it('turns away a request with no token', function () {
    $this->getJson('/api/v1/student/courses')->assertUnauthorized();
});

it('will not open the student API with a two factor challenge token', function () {
    $challenge = $this->student->createToken('challenge', ['two-factor'])->plainTextToken;

    $this->withToken($challenge)->getJson('/api/v1/student/courses')->assertForbidden();
});

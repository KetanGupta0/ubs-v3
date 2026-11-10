<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use App\Services\Lms\ResultCard;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');

    $this->owner = User::factory()->owner()->create();

    $this->course = Course::query()->create([
        'title' => 'Full stack internship', 'slug' => 'scoped-'.uniqid(), 'type' => 'internship',
        'tagline' => 'x', 'summary' => 'y', 'price' => 0, 'is_published' => true,
    ]);

    $batch = fn (string $name) => Batch::query()->create([
        'course_id' => $this->course->id, 'name' => $name, 'code' => 'BS-'.uniqid(),
        'starts_on' => today(), 'status' => 'running', 'is_published' => true,
    ]);
    $this->morning = $batch('Morning batch');
    $this->evening = $batch('Evening batch');

    $student = function (Batch $batch) {
        $user = User::factory()->student()->create();
        Enrollment::query()->create([
            'user_id' => $user->id, 'course_id' => $this->course->id, 'batch_id' => $batch->id,
            'enrolled_at' => now(), 'has_paid' => true,
        ]);

        return $user;
    };
    $this->early = $student($this->morning);
    $this->late = $student($this->evening);

    $quiz = function (string $title, ?Batch $batch) {
        $quiz = Quiz::query()->create([
            'course_id' => $this->course->id, 'batch_id' => $batch?->id, 'title' => $title,
            'attempts_allowed' => 1, 'pass_percent' => 50, 'is_published' => true,
            'closes_at' => now()->addDays(2),
        ]);
        Question::query()->create([
            'quiz_id' => $quiz->id, 'type' => 'mcq', 'body' => 'Pick one', 'options' => ['A', 'B'], 'correct' => ['A'], 'marks' => 1,
        ]);

        return $quiz;
    };
    $this->everyone = $quiz('Course wide check', null);
    $this->morningOnly = $quiz('Morning batch check', $this->morning);

    $module = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Week one']);
    $this->lesson = Lesson::query()->create([
        'course_id' => $this->course->id, 'course_module_id' => $module->id,
        'title' => 'Request to response', 'slug' => 'scoped-lesson-'.uniqid(), 'content' => 'Hello.',
    ]);

    Storage::disk('private')->put('courses/handout.pdf', '%PDF-1.4 test');
    $material = fn (string $title, ?Batch $batch) => Material::query()->create([
        'course_id' => $this->course->id, 'lesson_id' => $this->lesson->id, 'batch_id' => $batch?->id,
        'title' => $title, 'path' => 'courses/handout.pdf', 'mime' => 'application/pdf', 'size' => 12,
        'is_downloadable' => true,
    ]);
    $this->sharedHandout = $material('Slides for everyone', null);
    $this->morningHandout = $material('Morning batch worksheet', $this->morning);
});

it('shows a batch its own quiz and the course wide one, and other batches only the course wide one', function () {
    $titles = function (User $student) {
        $titles = [];
        $this->actingAs($student)->get("/student/courses/{$this->course->id}")
            ->assertInertia(function ($page) use (&$titles) {
                $titles = collect($page->toArray()['props']['quizzes'])->pluck('title')->sort()->values()->all();
            });

        return $titles;
    };

    expect($titles($this->early))->toBe(['Course wide check', 'Morning batch check'])
        ->and($titles($this->late))->toBe(['Course wide check']);
});

it('will not open another batch\'s quiz by its address', function () {
    $this->actingAs($this->late)->get("/student/quizzes/{$this->morningOnly->id}")->assertNotFound();
    $this->actingAs($this->late)->post("/student/quizzes/{$this->morningOnly->id}/start")->assertNotFound();

    $this->actingAs($this->early)->get("/student/quizzes/{$this->morningOnly->id}")->assertOk();
});

it('leaves another batch\'s quiz out of the dashboard and out of the result', function () {
    $this->actingAs($this->late)->get('/student')->assertDontSee('Morning batch check');

    $enrolment = Enrollment::query()->where('user_id', $this->late->id)->sole();

    expect(app(ResultCard::class)->for($enrolment)['quizzes']['total'])->toBe(1);
});

it('shows each batch only its own handouts, on the lesson and in the materials list', function () {
    $this->actingAs($this->late)->get("/student/courses/{$this->course->id}/lessons/{$this->lesson->id}")
        ->assertInertia(fn ($page) => $page->has('materials', 1)->where('materials.0.title', 'Slides for everyone'));

    $this->actingAs($this->late)->get("/student/courses/{$this->course->id}/materials")
        ->assertInertia(fn ($page) => $page->has('materials', 1));

    $this->actingAs($this->early)->get("/student/courses/{$this->course->id}/materials")
        ->assertInertia(fn ($page) => $page->has('materials', 2));
});

it('will not hand over another batch\'s file', function () {
    $this->actingAs($this->late)
        ->get("/student/courses/{$this->course->id}/materials/{$this->morningHandout->id}")
        ->assertNotFound();

    $this->actingAs($this->early)
        ->get("/student/courses/{$this->course->id}/materials/{$this->morningHandout->id}")
        ->assertOk();
});

it('keeps the mobile app to the same rule', function () {
    $token = $this->late->createToken('phone', ['*'])->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/v1/student/courses/{$this->course->id}/lessons/{$this->lesson->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.materials')
        ->assertJsonPath('data.materials.0.title', 'Slides for everyone');
});

it('only lets staff set a quiz for a batch of the same course', function () {
    $other = Course::query()->create([
        'title' => 'Other', 'slug' => 'other-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'y', 'is_published' => true,
    ]);
    $elsewhere = Batch::query()->create(['course_id' => $other->id, 'name' => 'Elsewhere', 'code' => 'EL-'.uniqid(), 'starts_on' => today()]);

    $this->actingAs($this->owner)->post("/admin/courses/{$this->course->id}/quizzes", [
        'title' => 'Sneaky', 'attempts_allowed' => 1, 'pass_percent' => 50, 'batch_id' => $elsewhere->id,
    ])->assertSessionHasErrors('batch_id');

    $this->actingAs($this->owner)->post("/admin/courses/{$this->course->id}/quizzes", [
        'title' => 'Evening check', 'attempts_allowed' => 1, 'pass_percent' => 50, 'batch_id' => $this->evening->id,
    ])->assertSessionHasNoErrors();

    expect(Quiz::query()->where('title', 'Evening check')->value('batch_id'))->toBe($this->evening->id);
});

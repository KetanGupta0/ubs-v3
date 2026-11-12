<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonDoubt;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\DoubtAnswered;
use App\Notifications\DoubtAsked;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->trainer = User::factory()->admin()->create(['name' => 'Meera Trainer']);
    $this->trainer->permissions()->attach(Permission::query()->where('key', 'lms.doubts')->first());

    $this->course = Course::query()->create([
        'title' => 'Python basics', 'slug' => 'doubt-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'x',
    ]);
    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id, 'name' => 'Morning batch', 'code' => 'DB-'.uniqid(), 'trainer_id' => $this->trainer->id,
    ]);
    $this->otherBatch = Batch::query()->create([
        'course_id' => $this->course->id, 'name' => 'Evening batch', 'code' => 'DB-'.uniqid(),
    ]);

    $this->enrol = function (string $name, ?Batch $batch) {
        $student = User::factory()->student()->create(['name' => $name]);
        Enrollment::query()->create([
            'user_id' => $student->id, 'course_id' => $this->course->id, 'batch_id' => $batch?->id,
            'enrolled_at' => now(), 'has_paid' => true,
        ]);

        return $student;
    };

    $this->asha = ($this->enrol)('Asha Verma', $this->batch);
    $this->ravi = ($this->enrol)('Ravi Kumar Singh', $this->batch);
    $this->evening = ($this->enrol)('Evening Student', $this->otherBatch);

    $module = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Week one']);
    $this->lesson = Lesson::query()->create(['course_id' => $this->course->id, 'course_module_id' => $module->id, 'title' => 'Loops', 'slug' => 'loops-'.uniqid()]);
    $this->locked = Lesson::query()->create([
        'course_id' => $this->course->id, 'course_module_id' => $module->id, 'title' => 'Functions', 'slug' => 'fn-'.uniqid(),
        'prerequisite_lesson_id' => $this->lesson->id,
    ]);

    $this->path = fn (Lesson $lesson) => "/student/courses/{$this->course->id}/lessons/{$lesson->id}";
});

it('takes a question, tells the batch trainer, and shows it to the asker\'s batch only', function () {
    $this->actingAs($this->asha)
        ->post(($this->path)($this->lesson).'/doubts', ['body' => 'Why does range(5) stop at 4?'])
        ->assertSessionHas('success');

    $doubt = LessonDoubt::query()->sole();
    expect($doubt->batch_id)->toBe($this->batch->id)->and($doubt->status)->toBe('open');
    Notification::assertSentTo($this->trainer, DoubtAsked::class);

    $this->actingAs($this->ravi)->get(($this->path)($this->lesson))
        ->assertInertia(fn ($page) => $page
            ->has('doubts', 1)
            ->where('doubts.0.asker', 'Asha V.')
            ->where('doubts.0.mine', false)
            ->where('doubts.0.body', 'Why does range(5) stop at 4?'));

    $this->actingAs($this->asha)->get(($this->path)($this->lesson))
        ->assertInertia(fn ($page) => $page->where('doubts.0.asker', 'You')->where('doubts.0.mine', true));

    $this->actingAs($this->evening)->get(($this->path)($this->lesson))
        ->assertInertia(fn ($page) => $page->has('doubts', 0));
});

it('refuses a question on a lesson the gate has not opened', function () {
    $this->actingAs($this->asha)
        ->post(($this->path)($this->locked).'/doubts', ['body' => 'What is a function?'])
        ->assertForbidden();

    expect(LessonDoubt::query()->count())->toBe(0);
});

it('lets the team answer, which tells the asker and shows the batch', function () {
    $this->actingAs($this->asha)->post(($this->path)($this->lesson).'/doubts', ['body' => 'Why does range(5) stop at 4?']);
    $doubt = LessonDoubt::query()->sole();

    $this->actingAs($this->trainer)->get('/admin/doubts?filter[status]=open')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/doubts/Index')->has('table.rows', 1)->where('waiting', 1));
    $this->actingAs($this->trainer)->get("/admin/doubts/{$doubt->id}")->assertOk();

    $this->actingAs($this->trainer)
        ->post("/admin/doubts/{$doubt->id}/reply", ['body' => 'range stops before the end you give it.'])
        ->assertSessionHas('success');

    expect($doubt->fresh()->status)->toBe('answered');
    Notification::assertSentTo($this->asha, DoubtAnswered::class, fn ($n) => str_contains($n->toArray($this->asha)['url'], "#doubt-{$doubt->id}"));

    $this->actingAs($this->ravi)->get(($this->path)($this->lesson))
        ->assertInertia(fn ($page) => $page
            ->where('doubts.0.status', 'answered')
            ->where('doubts.0.replies.0.author', 'Meera Trainer')
            ->where('doubts.0.replies.0.fromStaff', true));
});

it('lets only the asker follow up, which reopens it', function () {
    $this->actingAs($this->asha)->post(($this->path)($this->lesson).'/doubts', ['body' => 'Why does range(5) stop at 4?']);
    $doubt = LessonDoubt::query()->sole();
    $this->actingAs($this->trainer)->post("/admin/doubts/{$doubt->id}/reply", ['body' => 'It stops before the end.']);

    $this->actingAs($this->ravi)
        ->post(($this->path)($this->lesson)."/doubts/{$doubt->id}/reply", ['body' => 'Me too'])
        ->assertNotFound();

    $this->actingAs($this->asha)
        ->post(($this->path)($this->lesson)."/doubts/{$doubt->id}/reply", ['body' => 'And range(1, 5)?'])
        ->assertSessionHas('success');

    expect($doubt->fresh()->status)->toBe('open')->and($doubt->replies()->count())->toBe(2);
});

it('lets the team take a question down and put it back, on the record', function () {
    $this->actingAs($this->asha)->post(($this->path)($this->lesson).'/doubts', ['body' => 'Something off topic entirely']);
    $doubt = LessonDoubt::query()->sole();

    $this->actingAs($this->trainer)->put("/admin/doubts/{$doubt->id}/visibility", ['hidden' => true])->assertSessionHas('success');
    $this->actingAs($this->ravi)->get(($this->path)($this->lesson))->assertInertia(fn ($page) => $page->has('doubts', 0));

    $this->actingAs($this->trainer)->post("/admin/doubts/{$doubt->id}/reply", ['body' => 'x'])->assertStatus(422);

    $this->actingAs($this->trainer)->put("/admin/doubts/{$doubt->id}/visibility", ['hidden' => false]);
    expect($doubt->fresh()->status)->toBe('open');
    expect(AuditLog::query()->where('subject_type', LessonDoubt::class)->count())->toBe(2);
});

it('keeps the queue to staff who hold the permission', function () {
    $staff = User::factory()->admin()->create();
    $staff->permissions()->attach(Permission::query()->where('key', 'students.view')->first());

    $this->actingAs($staff)->get('/admin/doubts')->assertForbidden();
});

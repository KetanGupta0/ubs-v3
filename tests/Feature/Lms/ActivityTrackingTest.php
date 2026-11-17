<?php

use App\Models\ActivityLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Chat\Messenger;
use App\Services\Chat\Rooms;
use App\Services\Lms\ActivityHeatmap;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->course = Course::query()->create(['title' => 'Python basics', 'slug' => 'act-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'x']);
    $this->batch = Batch::query()->create(['course_id' => $this->course->id, 'name' => 'Morning batch', 'code' => 'AC-'.uniqid()]);

    $this->enrol = function (string $name) {
        $student = User::factory()->student()->create(['name' => $name, 'email' => str($name)->slug().'@example.test']);
        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id, 'enrolled_at' => now(), 'has_paid' => true]);

        return $student;
    };

    $this->asha = ($this->enrol)('Asha Verma');
    $this->ravi = ($this->enrol)('Ravi Kumar');
});

/* ------------------------------------------------------------- last seen */

it('notes when somebody was last here, at most once a minute', function () {
    $this->actingAs($this->asha)->get('/student')->assertOk();
    $first = $this->asha->fresh()->last_seen_at;

    expect($first)->not->toBeNull()->and($this->asha->fresh()->isOnline())->toBeTrue();

    $this->travel(30)->seconds();
    $this->actingAs($this->asha->fresh())->get('/student');
    expect($this->asha->fresh()->last_seen_at->equalTo($first))->toBeTrue();

    $this->travel(2)->minutes();
    $this->actingAs($this->asha->fresh())->get('/student');
    expect($this->asha->fresh()->last_seen_at->gt($first))->toBeTrue();

    $this->travel(10)->minutes();
    expect($this->asha->fresh()->isOnline())->toBeFalse();
});

it('does not count staff viewing as a student as that student being online', function () {
    $this->actingAs($this->owner)->post("/admin/students/{$this->asha->id}/view-as", ['reason' => 'Checking a lesson'])->assertRedirect('/student');
    $this->get('/student')->assertOk();

    expect($this->asha->fresh()->last_seen_at)->toBeNull();
});

/* ------------------------------------------------------------ the events */

it('records a student signing in, and nobody else', function () {
    $this->post('/login', ['identifier' => 'asha-verma@example.test', 'password' => 'Password123!'])->assertRedirect('/student');
    auth()->logout();
    $admin = User::factory()->admin()->create(['email' => 'staff@example.test']);
    $this->post('/login', ['identifier' => 'staff@example.test', 'password' => 'Password123!']);

    expect(ActivityLog::query()->where('action', 'signed.in')->pluck('user_id')->all())->toBe([$this->asha->id]);
});

it('records that a student posted in their batch group, never what they said', function () {
    $group = app(Rooms::class)->forBatch($this->batch);

    app(Messenger::class)->sendText($group, $this->asha, 'Has anybody finished the loops task?');
    app(Messenger::class)->sendText($group, $this->owner, 'Reminder: class at seven.');

    $log = ActivityLog::query()->where('action', 'group.posted')->sole();

    expect($log->user_id)->toBe($this->asha->id)
        ->and($log->batch_id)->toBe($this->batch->id)
        ->and(json_encode($log->meta))->not->toContain('loops');
});

/* -------------------------------------------------------------- heatmaps */

it('counts a student\'s activity per day, and a batch\'s active students per day, over whole weeks', function () {
    $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(12, 0));

    foreach ([[$this->asha, 0], [$this->asha, 0], [$this->ravi, 0], [$this->asha, 1]] as [$student, $daysAgo]) {
        ActivityLog::query()->create(['user_id' => $student->id, 'action' => 'lesson.opened', 'occurred_at' => now()->subDays($daysAgo)]);
    }
    $outsider = User::factory()->student()->create();
    ActivityLog::query()->create(['user_id' => $outsider->id, 'action' => 'lesson.opened', 'occurred_at' => now()]);

    $student = collect(app(ActivityHeatmap::class)->forStudent($this->asha))->keyBy('date');
    $batch = collect(app(ActivityHeatmap::class)->forBatch($this->batch))->keyBy('date');

    expect($student)->toHaveCount(ActivityHeatmap::WEEKS * 7)
        ->and(now()->parse($student->keys()->first())->isMonday())->toBeTrue()
        ->and($student[today()->toDateString()]['count'])->toBe(2)
        ->and($student[today()->subDay()->toDateString()]['count'])->toBe(1)
        ->and($student[today()->addDay()->toDateString()]['future'])->toBeTrue()
        // Two of the batch were active today; the outsider is not on it.
        ->and($batch[today()->toDateString()]['count'])->toBe(2)
        ->and($batch[today()->subDay()->toDateString()]['count'])->toBe(1);
});

it('shows the heatmaps, and who is online, where they are read', function () {
    $this->actingAs($this->asha)->get('/student/performance')
        ->assertInertia(fn ($page) => $page->has('heatmap', ActivityHeatmap::WEEKS * 7));

    $this->actingAs($this->owner)->get("/admin/students/{$this->asha->id}")
        ->assertInertia(fn ($page) => $page
            ->where('record.activity.online', true)
            ->has('record.activity.heatmap', ActivityHeatmap::WEEKS * 7));

    $this->actingAs($this->owner)->get("/admin/batches/{$this->batch->getRouteKey()}/performance")
        ->assertInertia(fn ($page) => $page
            ->has('online', 1)
            ->where('online.0.name', 'Asha Verma')
            ->has('heatmap', ActivityHeatmap::WEEKS * 7));
});

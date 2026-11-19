<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Step I: a list costs the same number of queries for three rows as for
 * thirty. Lazy loading inside a list throws outside production
 * (AppServiceProvider), and these pages are the ones where a browser found
 * a query per row that no test had shown.
 */

/** Reads a request makes, once the first visit's one-off work (a session, "last seen") is done. */
function queriesFor(Closure $request): int
{
    $request();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = collect(DB::getQueryLog())->filter(fn (array $query) => str_starts_with(strtolower(ltrim($query['query'])), 'select'))->count();
    DB::disableQueryLog();

    return $count;
}

beforeEach(fn () => $this->seed(PermissionSeeder::class));

it('lists staff, with their permissions and two-step sign in, in the same queries however many there are', function () {
    $owner = User::factory()->owner()->create();
    $hire = function () {
        $user = User::factory()->admin()->create();
        $user->permissions()->attach(Permission::query()->inRandomOrder()->limit(3)->pluck('id'));
        $user->twoFactorSecret()->create(['secret' => 'JBSWY3DPEHPK3PXP', 'recovery_codes' => [], 'confirmed_at' => now()]);
    };

    foreach (range(1, 3) as $i) {
        $hire();
    }
    $few = queriesFor(fn () => $this->actingAs($owner)->get('/admin/staff')->assertOk());

    foreach (range(1, 6) as $i) {
        $hire();
    }
    $many = queriesFor(fn () => $this->actingAs($owner)->get('/admin/staff')->assertOk()
        ->assertInertia(fn ($page) => $page->has('staff', 10)
            ->where('staff', fn ($staff) => collect($staff)->where('twoFactorEnabled', true)->count() === 9)));

    expect($many)->toBe($few);
});

it('shows a course\'s lessons, each with what it waits for, in the same queries however many there are', function () {
    $student = User::factory()->student()->create();
    $course = Course::query()->create(['title' => 'Python basics', 'slug' => 'budget-'.uniqid(), 'type' => 'course', 'tagline' => 'From zero', 'summary' => 'Six weeks.']);
    $batch = Batch::query()->create(['course_id' => $course->id, 'name' => 'Morning', 'code' => 'QB-'.uniqid(), 'starts_on' => today()->subWeek()]);
    Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'batch_id' => $batch->id, 'enrolled_at' => now()->subWeek(), 'has_paid' => true]);

    $previous = null;
    $add = function (int $lessons) use ($course, &$previous) {
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title' => 'Week '.uniqid(), 'sort_order' => 1, 'unlock_after_days' => 1]);

        foreach (range(1, $lessons) as $i) {
            $previous = Lesson::query()->create([
                'course_id' => $course->id, 'course_module_id' => $module->id, 'title' => "Lesson {$i}",
                'slug' => 'lesson-'.uniqid(), 'sort_order' => $i, 'prerequisite_lesson_id' => $previous?->id,
            ]);
        }
    };

    $add(3);
    $few = queriesFor(fn () => $this->actingAs($student)->get("/student/courses/{$course->id}")->assertOk());

    $add(9);
    $many = queriesFor(fn () => $this->actingAs($student)->get("/student/courses/{$course->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('modules.1.lessons.0.lock.reason', fn ($reason) => str_starts_with((string) $reason, 'Finish “Lesson 3”'))));

    // One more module is a little more work; another nine lessons is none.
    expect($many - $few)->toBeLessThanOrEqual(1);
});

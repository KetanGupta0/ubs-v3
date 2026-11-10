<?php

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Course;
use App\Models\LiveSession;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();

    $course = fn (string $title) => Course::query()->create([
        'title' => $title, 'slug' => str($title)->slug().'-'.uniqid(), 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'price' => 0, 'is_published' => true,
    ]);
    $this->vue = $course('Vue.js in depth');
    $this->sql = $course('SQL mastery');

    $batch = fn (Course $course, string $name) => Batch::query()->create([
        'course_id' => $course->id, 'name' => $name, 'code' => 'CS-'.uniqid(),
        'starts_on' => today(), 'status' => 'running', 'is_published' => true,
    ]);
    $this->vueBatch = $batch($this->vue, 'Vue November');
    $this->sqlBatch = $batch($this->sql, 'SQL November');

    $this->session = fn (Batch $batch, string $title, $when, string $status = 'scheduled') => LiveSession::query()->create([
        'batch_id' => $batch->id, 'title' => $title, 'scheduled_at' => $when,
        'duration_minutes' => 90, 'status' => $status,
    ]);
});

function classRows($test, string $query = ''): array
{
    $rows = [];
    $test->actingAs($test->owner)
        ->get('/admin/classes'.$query)
        ->assertOk()
        ->assertInertia(function ($page) use (&$rows) {
            $rows = $page->toArray()['props']['table']['rows'];
        });

    return collect($rows)->pluck('title')->all();
}

it('lists upcoming classes across every batch, soonest first', function () {
    ($this->session)($this->sqlBatch, 'Joins', now()->addDays(2));
    ($this->session)($this->vueBatch, 'Components', now()->addDay());
    ($this->session)($this->vueBatch, 'Routing', now()->subDays(2), 'held');
    ($this->session)($this->vueBatch, 'Called off', now()->addDays(3), 'cancelled');

    expect(classRows($this, '?filter[when]=upcoming&sort=scheduled_at'))->toBe(['Components', 'Joins']);
});

it('finds classes that were held but whose register was never marked', function () {
    $student = User::factory()->student()->create();

    ($this->session)($this->vueBatch, 'Forgotten register', now()->subDays(1), 'held');
    ($this->session)($this->vueBatch, 'Never marked held either', now()->subDays(2));
    $marked = ($this->session)($this->vueBatch, 'Marked properly', now()->subDays(3), 'held');
    Attendance::query()->create(['live_session_id' => $marked->id, 'user_id' => $student->id, 'status' => 'present']);
    ($this->session)($this->vueBatch, 'Cancelled', now()->subDays(4), 'cancelled');
    ($this->session)($this->vueBatch, 'Still on', now()->subHour());

    expect(classRows($this, '?filter[when]=unmarked&sort=-scheduled_at'))
        ->toBe(['Forgotten register', 'Never marked held either']);

    $this->actingAs($this->owner)
        ->get('/admin/classes')
        ->assertInertia(fn ($page) => $page->where('counts.unmarked', 2));
});

it('shows attendance as present out of marked', function () {
    $session = ($this->session)($this->vueBatch, 'Components', now()->subDay(), 'held');

    foreach (['present', 'late', 'absent'] as $status) {
        Attendance::query()->create([
            'live_session_id' => $session->id,
            'user_id' => User::factory()->student()->create()->id,
            'status' => $status,
        ]);
    }

    $this->actingAs($this->owner)
        ->get('/admin/classes')
        ->assertInertia(fn ($page) => $page
            ->where('table.rows.0.attendance', '2 of 3')
            ->where('table.rows.0.unmarked', false));
});

it('filters by course and searches by batch', function () {
    ($this->session)($this->sqlBatch, 'Joins', now()->addDay());
    ($this->session)($this->vueBatch, 'Components', now()->addDay());

    expect(classRows($this, "?filter[course]={$this->sql->id}"))->toBe(['Joins'])
        ->and(classRows($this, '?q=Vue+November'))->toBe(['Components']);
});

it('is open to staff who look after students, and to nobody else', function () {
    $trainer = User::factory()->admin()->create();
    $trainer->permissions()->attach(Permission::query()->where('key', 'students.view')->sole());
    $this->actingAs($trainer->fresh())->get('/admin/classes')->assertOk();

    $sales = User::factory()->admin()->create();
    $sales->permissions()->attach(Permission::query()->where('key', 'leads.view')->sole());
    $this->actingAs($sales->fresh())->get('/admin/classes')->assertForbidden();
});

<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/*
 * Deleting a student or client account (owner's request, 2026-11-21): only one
 * that holds nothing, such as a bot's sign up; anything with a record is
 * refused, named, and left to be suspended.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

function staffHolding(string ...$keys): User
{
    $staff = User::factory()->admin()->create();
    $staff->permissions()->attach(Permission::query()->whereIn('key', ['clients.view', 'students.view', ...$keys])->pluck('id'));

    return $staff->fresh();
}

it('deletes an account that holds nothing, for good, and records it', function () {
    $bot = User::factory()->client()->create(['name' => 'Spam Bot', 'email' => 'bot@example.com']);
    $bot->profile()->create([]);

    $this->actingAs($this->owner)
        ->delete("/admin/clients/{$bot->id}")
        ->assertRedirect('/admin/clients')
        ->assertSessionHas('success');

    expect(User::withTrashed()->whereKey($bot->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'deleted')->where('subject_id', $bot->id)->sole()->subject_label)->toContain('bot@example.com');

    // The address is free again.
    expect(User::factory()->client()->create(['email' => 'bot@example.com'])->exists)->toBeTrue();
});

it('refuses an account with a record, says what keeps it, and deletes nothing', function () {
    $student = User::factory()->student()->create();
    $course = Course::query()->create(['title' => 'Web', 'slug' => 'web-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'x']);
    $batch = Batch::query()->create(['course_id' => $course->id, 'name' => 'June', 'code' => 'J-'.uniqid(), 'starts_on' => today()]);
    Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'batch_id' => $batch->id, 'enrolled_at' => now()]);

    $this->actingAs($this->owner)
        ->from("/admin/students/{$student->id}")
        ->delete("/admin/students/{$student->id}")
        ->assertRedirect("/admin/students/{$student->id}")
        ->assertSessionHas('error', fn (string $message) => str_contains($message, '1 enrollment') && str_contains($message, 'Suspend it instead'));

    expect($student->fresh())->not->toBeNull()
        ->and(Enrollment::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'deleted')->count())->toBe(0);
});

it('takes the permission for the role being deleted', function () {
    $student = User::factory()->student()->create();
    $client = User::factory()->client()->create();

    $this->actingAs(staffHolding())->delete("/admin/students/{$student->id}")->assertForbidden();

    $deletesStudents = staffHolding('students.delete');
    $this->actingAs($deletesStudents)->delete("/admin/clients/{$client->id}")->assertForbidden();
    $this->actingAs($deletesStudents)->delete("/admin/students/{$student->id}")->assertRedirect('/admin/students');

    expect($student->fresh())->toBeNull()
        ->and($client->fresh())->not->toBeNull();
});

it('never deletes staff or the owner through this door', function () {
    $staff = User::factory()->admin()->create();

    $this->actingAs($this->owner)->delete("/admin/students/{$staff->id}")->assertNotFound();
    $this->actingAs($this->owner)->delete("/admin/clients/{$this->owner->id}")->assertNotFound();

    expect($staff->fresh())->not->toBeNull();
});

it('shows the button only to those who may delete', function () {
    $student = User::factory()->student()->create();

    $this->actingAs($this->owner)->get("/admin/students/{$student->id}")
        ->assertInertia(fn ($page) => $page->where('canDelete', true));

    $this->actingAs(staffHolding())->get("/admin/students/{$student->id}")
        ->assertInertia(fn ($page) => $page->where('canDelete', false));
});

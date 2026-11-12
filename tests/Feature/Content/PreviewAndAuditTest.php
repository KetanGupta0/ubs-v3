<?php

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\Permission;
use App\Models\Service;
use App\Models\Solution;
use App\Models\SolutionCategory;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();

    $this->editor = User::factory()->admin()->create();
    $this->editor->permissions()->attach(Permission::query()->where('key', 'catalogue.view')->sole());
    $this->editor = $this->editor->fresh();

    $this->billing = User::factory()->admin()->create();
    $this->billing->permissions()->attach(Permission::query()->where('key', 'billing.view')->sole());
    $this->billing = $this->billing->fresh();

    $category = SolutionCategory::query()->create(['name' => 'Healthcare', 'slug' => 'healthcare']);
    $this->solution = Solution::query()->create([
        'solution_category_id' => $category->id, 'title' => 'Clinic suite', 'slug' => 'clinic-suite',
        'tagline' => 't', 'summary' => 'Not ready yet.', 'is_published' => false,
    ]);
    $this->service = Service::query()->create([
        'title' => 'Audits', 'slug' => 'audits', 'type' => 'development', 'tagline' => 't', 'summary' => 's',
        'deliverables' => [], 'engagement_models' => [], 'is_published' => false,
    ]);
    $this->course = Course::query()->create([
        'title' => 'Rust in depth', 'slug' => 'rust-in-depth', 'type' => 'course', 'tagline' => 't', 'summary' => 's',
        'visibility' => 'public', 'is_published' => false,
    ]);
    $this->internship = Course::query()->create([
        'title' => 'Data internship', 'slug' => 'data-internship', 'type' => 'internship', 'tagline' => 't', 'summary' => 's',
        'visibility' => 'public', 'is_published' => false,
    ]);
});

dataset('unpublished pages', [
    'solution' => ['/solutions/clinic-suite'],
    'service' => ['/services/audits'],
    'course' => ['/training/rust-in-depth'],
    'internship' => ['/internships/data-internship'],
]);

it('keeps an unpublished item not found for everybody but catalogue staff', function (string $path) {
    $this->get($path)->assertNotFound();
    $this->actingAs(User::factory()->student()->create())->get($path)->assertNotFound();
    $this->actingAs($this->billing)->get($path)->assertNotFound();
})->with('unpublished pages');

it('opens an unpublished item for catalogue staff, marked and kept out of search', function (string $path) {
    $this->actingAs($this->editor)->get($path)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('previewNotice', fn ($notice) => str_starts_with($notice, 'Not published.'))
            ->where('seo.noindex', true));
})->with('unpublished pages');

it('shows a published item to staff as visitors see it, with no preview notice', function () {
    $this->solution->update(['is_published' => true]);

    $this->actingAs($this->editor)->get('/solutions/clinic-suite')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('previewNotice')->where('seo.noindex', false));
});

it('still never opens a course kept inside the LMS, or an item at the wrong address', function () {
    $this->course->update(['visibility' => 'lms_only']);

    $this->actingAs($this->owner)->get('/training/rust-in-depth')->assertNotFound();
    $this->actingAs($this->owner)->get('/training/data-internship')->assertNotFound();
    $this->actingAs($this->owner)->get('/internships/rust-in-depth')->assertNotFound();
});

it('records every change made in the course builder', function () {
    Storage::fake('private');
    $base = "/admin/courses/{$this->course->id}";

    $this->actingAs($this->owner)->post("{$base}/modules", ['title' => 'Ownership'])->assertSessionHasNoErrors();
    $module = CourseModule::query()->sole();
    $this->actingAs($this->owner)->put("{$base}/modules/{$module->id}", ['title' => 'Ownership and borrowing']);
    $this->actingAs($this->owner)->post("{$base}/modules/reorder", ['order' => [$module->id]]);

    $this->actingAs($this->owner)->post("{$base}/modules/{$module->id}/lessons", ['title' => 'Borrowing'])->assertSessionHasNoErrors();
    $lesson = Lesson::query()->sole();
    $this->actingAs($this->owner)->put("{$base}/lessons/{$lesson->id}", ['title' => 'Borrowing rules']);
    $this->actingAs($this->owner)->post("{$base}/modules/{$module->id}/lessons/reorder", ['order' => [$lesson->id]]);

    $this->actingAs($this->owner)->post("{$base}/materials", [
        'title' => 'Slides', 'file' => UploadedFile::fake()->create('slides.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $material = Material::query()->sole();
    Storage::disk('private')->assertExists($material->path);

    $this->actingAs($this->owner)->delete("{$base}/materials/{$material->id}")->assertSessionHas('success');
    Storage::disk('private')->assertMissing($material->path);

    $actions = AuditLog::query()->orderBy('id')->get()->map(fn ($entry) => $entry->action.' '.class_basename((string) $entry->subject_type))->all();

    expect($actions)->toContain('created CourseModule', 'updated CourseModule', 'course.modules_reordered Course')
        ->toContain('created Lesson', 'updated Lesson', 'course.lessons_reordered CourseModule')
        ->toContain('created Material', 'deleted Material');

    $renamed = AuditLog::query()->where('action', 'updated')->where('subject_type', CourseModule::class)->sole();
    expect($renamed->changes['title'])->toBe(['from' => 'Ownership', 'to' => 'Ownership and borrowing']);
});

it('records drafts of page wording, and throwing them away', function () {
    $this->actingAs($this->owner)->put('/admin/copy/home', ['values' => ['hero.badge' => 'Build · Teach']]);
    $this->actingAs($this->owner)->delete('/admin/copy/home/drafts');

    $drafted = AuditLog::query()->where('action', 'copy.drafted')->sole();
    expect($drafted->changes['hero.badge']['to'])->toBe('Build · Teach')
        ->and(AuditLog::query()->where('action', 'copy.discarded')->exists())->toBeTrue();
});

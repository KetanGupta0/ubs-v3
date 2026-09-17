<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\Quiz;
use App\Services\Admin\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Building a course: modules, lessons and the files attached to them.
 *
 * One screen for the whole structure rather than a page per level, because
 * ordering and unlocking rules only make sense next to each other.
 */
class CourseBuilderController extends Controller
{
    public function show(Course $course): Response
    {
        $course->load([
            'modules.lessons' => fn ($query) => $query->orderBy('sort_order'),
            'modules.lessons.materials',
        ]);

        return Inertia::render('admin/courses/Builder', [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'type' => $course->type,
                'lessonCount' => $course->lessonCount(),
                'isPublished' => $course->is_published,
            ],
            'modules' => $course->modules->map(fn (CourseModule $module) => [
                'id' => $module->id,
                'title' => $module->title,
                'summary' => $module->summary,
                'sortOrder' => $module->sort_order,
                'unlockAfterDays' => $module->unlock_after_days,
                'isPublished' => $module->is_published,
                'lessons' => $module->lessons->map(fn (Lesson $lesson) => [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'summary' => $lesson->summary,
                    'content' => $lesson->content,
                    'videoUrl' => $lesson->video_url,
                    'durationMinutes' => $lesson->duration_minutes,
                    'sortOrder' => $lesson->sort_order,
                    'unlockAfterDays' => $lesson->unlock_after_days,
                    'unlockAt' => $lesson->unlock_at?->toDateString(),
                    'prerequisiteLessonId' => $lesson->prerequisite_lesson_id,
                    'requiresPayment' => $lesson->requires_payment,
                    'requiredQuizId' => $lesson->required_quiz_id,
                    'minQuizScore' => $lesson->min_quiz_score,
                    'isPreview' => $lesson->is_preview,
                    'isPublished' => $lesson->is_published,
                    'materials' => $lesson->materials->map(fn (Material $material) => [
                        'id' => $material->id,
                        'title' => $material->title,
                        'kind' => $material->kind(),
                        'size' => $material->sizeLabel(),
                        'isLink' => $material->isLink(),
                        'url' => $material->external_url,
                        'downloadable' => $material->is_downloadable,
                    ]),
                ]),
            ]),
            'allLessons' => $course->lessons()
                ->orderBy('sort_order')
                ->get(['id', 'title'])
                ->map(fn (Lesson $lesson) => ['value' => $lesson->id, 'label' => $lesson->title]),
            'quizzes' => Quiz::query()
                ->where('course_id', $course->id)
                ->get(['id', 'title'])
                ->map(fn (Quiz $quiz) => ['value' => $quiz->id, 'label' => $quiz->title]),
        ]);
    }

    /* ---------------------------------------------------------- modules */

    public function storeModule(Request $request, Course $course): RedirectResponse
    {
        $course->modules()->create($this->moduleRules($request) + [
            'sort_order' => ($course->modules()->max('sort_order') ?? -1) + 1,
        ]);

        return back()->with('success', 'Module added.');
    }

    public function updateModule(Request $request, Course $course, CourseModule $module): RedirectResponse
    {
        abort_unless($module->course_id === $course->id, 404);

        $module->fill($this->moduleRules($request))->save();

        return back()->with('success', 'Module saved.');
    }

    public function destroyModule(Course $course, CourseModule $module, Auditor $auditor): RedirectResponse
    {
        abort_unless($module->course_id === $course->id, 404);

        $auditor->deleted($module, $module->title);
        $module->delete();

        return back()->with('success', 'Module removed, along with its lessons.');
    }

    public function reorderModules(Request $request, Course $course): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach ($validated['order'] as $position => $id) {
            CourseModule::query()
                ->where('course_id', $course->id)
                ->where('id', $id)
                ->update(['sort_order' => $position]);
        }

        return back();
    }

    /* ---------------------------------------------------------- lessons */

    public function storeLesson(Request $request, Course $course, CourseModule $module): RedirectResponse
    {
        abort_unless($module->course_id === $course->id, 404);

        $validated = $this->lessonRules($request, $course);

        $module->lessons()->create($validated + [
            'course_id' => $course->id,
            'sort_order' => ($module->lessons()->max('sort_order') ?? -1) + 1,
        ]);

        return back()->with('success', 'Lesson added.');
    }

    public function updateLesson(Request $request, Course $course, Lesson $lesson): RedirectResponse
    {
        abort_unless($lesson->course_id === $course->id, 404);

        $lesson->fill($this->lessonRules($request, $course, $lesson))->save();

        return back()->with('success', 'Lesson saved.');
    }

    public function destroyLesson(Course $course, Lesson $lesson, Auditor $auditor): RedirectResponse
    {
        abort_unless($lesson->course_id === $course->id, 404);

        $auditor->deleted($lesson, $lesson->title);
        $lesson->delete();

        return back()->with('success', 'Lesson removed.');
    }

    public function reorderLessons(Request $request, Course $course, CourseModule $module): RedirectResponse
    {
        abort_unless($module->course_id === $course->id, 404);

        $validated = $this->validatedInput($request, [
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        foreach ($validated['order'] as $position => $id) {
            Lesson::query()
                ->where('course_module_id', $module->id)
                ->where('id', $id)
                ->update(['sort_order' => $position]);
        }

        return back();
    }

    /* -------------------------------------------------------- materials */

    public function storeMaterial(Request $request, Course $course): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'lesson_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'max:51200'],
            'external_url' => ['nullable', 'url', 'max:255'],
            'is_downloadable' => ['boolean'],
        ], [
            'file.max' => 'That file is over 50 MB. Host it and add the link instead.',
        ]);

        if (blank($validated['file']) && blank($validated['external_url'])) {
            return back()->withErrors(['file' => 'Attach a file or give a link.']);
        }

        $lesson = $validated['lesson_id']
            ? Lesson::query()->where('course_id', $course->id)->find($validated['lesson_id'])
            : null;

        $attributes = [
            'course_id' => $course->id,
            'lesson_id' => $lesson?->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'external_url' => $validated['external_url'],
            'is_downloadable' => $this->boolInput($request, 'is_downloadable', true),
            'uploaded_by' => $request->user()->id,
            'sort_order' => (Material::query()->where('course_id', $course->id)->max('sort_order') ?? -1) + 1,
        ];

        if ($file = $request->file('file')) {
            $attributes['path'] = $file->store("courses/{$course->id}", 'private');
            $attributes['mime'] = $file->getClientMimeType();
            $attributes['size'] = $file->getSize();
        }

        Material::query()->create($attributes);

        return back()->with('success', 'Material added.');
    }

    public function destroyMaterial(Course $course, Material $material): RedirectResponse
    {
        abort_unless($material->course_id === $course->id, 404);

        $material->delete();

        return back()->with('success', 'Material removed.');
    }

    public function downloadMaterial(Course $course, Material $material): StreamedResponse
    {
        abort_unless($material->course_id === $course->id, 404);
        abort_unless($material->path && Storage::disk('private')->exists($material->path), 404);

        return Storage::disk('private')->download($material->path, $material->title);
    }

    /* ---------------------------------------------------------- helpers */

    /** @return array<string, mixed> */
    protected function moduleRules(Request $request): array
    {
        return $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:500'],
            'unlock_after_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'is_published' => ['boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    protected function lessonRules(Request $request, Course $course, ?Lesson $lesson = null): array
    {
        $validated = $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9-]+$/'],
            'summary' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string', 'max:60000'],
            'video_url' => ['nullable', 'url', 'max:255'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'unlock_after_days' => ['nullable', 'integer', 'min:0', 'max:730'],
            'unlock_at' => ['nullable', 'date'],
            'prerequisite_lesson_id' => ['nullable', 'integer', Rule::exists('lessons', 'id')->where('course_id', $course->id)],
            'requires_payment' => ['boolean'],
            'required_quiz_id' => ['nullable', 'integer', Rule::exists('quizzes', 'id')->where('course_id', $course->id)],
            'min_quiz_score' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_preview' => ['boolean'],
            'is_published' => ['boolean'],
        ]);

        // A lesson cannot be its own prerequisite, which is a lock nobody can
        // ever open.
        if ($lesson && $validated['prerequisite_lesson_id'] === $lesson->id) {
            $validated['prerequisite_lesson_id'] = null;
        }

        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['title']).'-'.Str::lower(Str::random(4));

        return $validated;
    }
}

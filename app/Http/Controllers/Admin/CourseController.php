<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Admin\Auditor;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Courses, programmes and internships.
 *
 * They share a table because structurally they are the same object. The `type`
 * decides which fields matter and which public path it is served from, so the
 * form shows the internship fields only when they apply.
 */
class CourseController extends Controller
{
    public const TYPES = ['course', 'programme', 'internship'];

    public const LEVELS = ['beginner', 'intermediate', 'advanced'];

    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/catalogue/courses/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'all' => Course::query()->count(),
                'internships' => Course::query()->internships()->count(),
                'courses' => Course::query()->taught()->count(),
                'hidden' => Course::query()->where('visibility', 'lms_only')->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/catalogue/courses/Form', [
            'course' => null,
            'defaultType' => in_array($request->query('type'), self::TYPES, true)
                ? $request->query('type')
                : 'course',
            'types' => self::TYPES,
            'levels' => self::LEVELS,
            'accents' => $this->accents(),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $course = Course::query()->create($this->validated($request));
        $auditor->created($course);

        return redirect()
            ->route('admin.courses.edit', $course)
            ->with('success', Str::ucfirst($course->type).' created.');
    }

    public function edit(Course $course): Response
    {
        return Inertia::render('admin/catalogue/courses/Form', [
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'slug' => $course->slug,
                'type' => $course->type,
                'tagline' => $course->tagline,
                'summary' => $course->summary,
                'description' => $course->description,
                'level' => $course->level,
                'duration_weeks' => $course->duration_weeks,
                'duration_months' => $course->duration_months,
                'hours_per_week' => $course->hours_per_week,
                'price' => $course->price,
                'sale_price' => $course->sale_price,
                'visibility' => $course->visibility,
                'mode' => $course->mode,
                'project_focus' => $course->project_focus,
                'audience' => $course->audience ?? [],
                'prerequisites' => $course->prerequisites ?? [],
                'tools' => $course->tools ?? [],
                'outcomes' => $course->outcomes ?? [],
                'documents_provided' => $course->documents_provided ?? [],
                'syllabus' => $course->syllabus ?? [],
                'accent' => $course->accent,
                'is_featured' => $course->is_featured,
                'is_published' => $course->is_published,
                'sort_order' => $course->sort_order,
                'publicUrl' => $course->visibility === 'public'
                    ? ($course->isInternship() ? "/internships/{$course->slug}" : "/training/{$course->slug}")
                    : null,
                'batchCount' => $course->batches()->count(),
            ],
            'defaultType' => $course->type,
            'types' => self::TYPES,
            'levels' => self::LEVELS,
            'accents' => $this->accents(),
        ]);
    }

    public function update(Request $request, Course $course, Auditor $auditor): RedirectResponse
    {
        $course->fill($this->validated($request, $course));
        $auditor->updated($course);
        $course->save();

        return back()->with('success', 'Saved.');
    }

    public function togglePublished(Course $course, Auditor $auditor): RedirectResponse
    {
        $course->is_published = ! $course->is_published;
        $auditor->updated($course);
        $course->save();

        return back()->with('success', $course->is_published ? 'Published.' : 'Unpublished.');
    }

    public function destroy(Course $course, Auditor $auditor): RedirectResponse
    {
        // Batches carry enrolments, so deleting a course with batches would take
        // student records with it. Unpublishing is almost always what is meant.
        if ($course->batches()->exists()) {
            return back()->withErrors([
                'course' => 'This has batches attached. Unpublish it instead, or remove the batches first.',
            ]);
        }

        $auditor->deleted($course);
        $course->delete();

        return redirect()->route('admin.courses.index')->with('success', 'Deleted.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Course $course = null): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9-]+$/', Rule::unique('courses', 'slug')->ignore($course?->id)],
            'type' => ['required', Rule::in(self::TYPES)],
            'tagline' => ['required', 'string', 'max:200'],
            'summary' => ['required', 'string', 'max:600'],
            'description' => ['nullable', 'string', 'max:20000'],

            'level' => ['required', Rule::in(self::LEVELS)],
            'duration_weeks' => ['nullable', 'integer', 'min:1', 'max:520'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'hours_per_week' => ['nullable', 'integer', 'min:1', 'max:80'],
            'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'sale_price' => ['nullable', 'integer', 'min:0', 'lt:price'],

            'visibility' => ['required', Rule::in(['public', 'lms_only'])],
            'mode' => ['required', Rule::in(['remote', 'hybrid', 'onsite'])],
            'project_focus' => ['nullable', 'string', 'max:300'],

            'audience' => ['array'],
            'audience.*' => ['string', 'max:200'],
            'prerequisites' => ['array'],
            'prerequisites.*' => ['string', 'max:200'],
            'tools' => ['array'],
            'tools.*' => ['string', 'max:80'],
            'outcomes' => ['array'],
            'outcomes.*' => ['string', 'max:300'],

            'documents_provided' => ['array'],
            'documents_provided.*.title' => ['required', 'string', 'max:120'],
            'documents_provided.*.body' => ['required', 'string', 'max:500'],

            'syllabus' => ['array'],
            'syllabus.*.module' => ['required', 'string', 'max:160'],
            'syllabus.*.weeks' => ['nullable', 'string', 'max:40'],
            'syllabus.*.topics' => ['array'],
            'syllabus.*.topics.*' => ['string', 'max:200'],

            'accent' => ['required', Rule::in($this->accents())],
            'is_featured' => ['boolean'],
            'is_published' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'sale_price.lt' => 'A sale price has to be below the normal price.',
        ]);

        $validated['slug'] = ($validated['slug'] ?? null) ?: Str::slug($validated['title']);
        $validated['sort_order'] ??= 0;

        $validated['seo'] = [
            'title' => $validated['title'].' — Unboundbyte Solutions',
            'description' => Str::limit($validated['summary'], 150),
        ];

        return $validated;
    }

    protected function table(): Table
    {
        return Table::for(Course::query()->withCount('batches'))
            ->searchable(['title', 'tagline', 'summary'])
            ->defaultSort('sort_order')
            ->exportName('courses-and-internships')
            ->columns([
                Column::make('title', 'Title')->sortable(),
                Column::make('type', 'Type')->sortable(),
                Column::make('level', 'Level')->sortable(),
                Column::make('duration', 'Duration'),
                Column::make('fee', 'Fee')->numeric(),
                Column::make('state', 'State'),
                Column::make('batches', 'Batches')->numeric(),
                Column::make('updated_at', 'Updated')->sortable(),
            ])
            ->filters([
                Filter::select('type', self::TYPES, 'Type')->placeholder('Any type'),
                Filter::select('level', self::LEVELS, 'Level')->placeholder('Any level'),
                Filter::select('visibility', [
                    ['value' => 'public', 'label' => 'On the public site'],
                    ['value' => 'lms_only', 'label' => 'Inside the LMS only'],
                ], 'Visibility')->placeholder('Any'),
                Filter::boolean('is_published', 'Published only'),
            ])
            ->transform(fn (Course $course) => [
                'id' => $course->id,
                'slug' => $course->slug,
                'title' => $course->title,
                'type' => $course->type,
                'level' => $course->level,
                'duration' => $course->durationLabel() ?? '—',
                'fee' => $course->price ? '₹'.number_format($course->effectivePrice()) : 'On request',
                'state' => match (true) {
                    ! $course->is_published => 'Draft',
                    $course->visibility === 'lms_only' => 'LMS only',
                    default => 'Live',
                },
                'published' => $course->is_published,
                'batches' => $course->batches_count,
                'updated_at' => $course->updated_at?->diffForHumans(),
            ]);
    }

    /** @return array<int, string> */
    protected function accents(): array
    {
        return ['brand', 'accent', 'violet', 'emerald', 'amber', 'rose'];
    }
}

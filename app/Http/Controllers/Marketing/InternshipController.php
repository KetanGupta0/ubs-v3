<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internships for college students.
 *
 * A separate listing from training, because it is a different product sold to a
 * different person. A student here is usually meeting a curriculum requirement
 * as well as trying to learn, so the paperwork is stated as prominently as the
 * syllabus.
 */
class InternshipController extends Controller
{
    public function index(Request $request): Response
    {
        $duration = $request->query('duration') ?: null;
        $level = $request->query('level') ?: null;

        $internships = Course::query()
            ->publiclyVisible()
            ->internships()
            ->with('batches')
            ->when($level, fn (Builder $q, string $value) => $q->where('level', $value))
            ->when($duration === 'short', fn (Builder $q) => $q->where('duration_weeks', '<=', 6))
            ->when($duration === 'medium', fn (Builder $q) => $q->whereBetween('duration_weeks', [7, 12]))
            ->when($duration === 'long', fn (Builder $q) => $q->where('duration_weeks', '>', 12))
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('marketing/internships/Index', [
            'internships' => $internships->map->toCardArray()->values(),
            'filters' => ['duration' => $duration, 'level' => $level],
            'levels' => Course::query()->publiclyVisible()->internships()->distinct()->orderBy('level')->pluck('level'),
            'totalCount' => Course::query()->publiclyVisible()->internships()->count(),
            'seo' => Seo::for(
                'Internships for college students',
                'Remote internships with a real project, a mentor who reviews your code every week, and the four documents your college asks for. Web, full stack, frontend, backend and data.',
                '/internships',
                Seo::breadcrumbs([
                    ['label' => 'Home', 'href' => '/'],
                    ['label' => 'Internships', 'href' => '/internships'],
                ]),
            ),
        ]);
    }

    public function show(Course $course): Response
    {
        abort_unless(
            $course->is_published && $course->visibility === 'public' && $course->isInternship(),
            404,
        );

        $batches = $course->batches()
            ->where('is_published', true)
            ->whereIn('status', ['upcoming', 'running'])
            ->orderBy('starts_on')
            ->get();

        return Inertia::render('marketing/internships/Show', [
            'internship' => [
                ...$course->toCardArray(),
                'description' => $course->description,
                'syllabus' => $course->syllabus ?? [],
                'outcomes' => $course->outcomes ?? [],
                'prerequisites' => $course->prerequisites ?? [],
                'audience' => $course->audience ?? [],
                'tools' => $course->tools ?? [],
                'documents' => $course->documents_provided ?? [],
            ],
            'batches' => $batches->map->toPublicArray()->values(),
            'related' => Course::query()
                ->publiclyVisible()
                ->internships()
                ->whereKeyNot($course->getKey())
                ->with('batches')
                ->orderBy('sort_order')
                ->limit(3)
                ->get()
                ->map->toCardArray()
                ->values(),
            'seo' => Seo::for(
                $course->seo['title'] ?? $course->title,
                $course->seo['description'] ?? $course->summary,
                "/internships/{$course->slug}",
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Course',
                    'name' => $course->title,
                    'description' => $course->summary,
                    'provider' => ['@type' => 'Organization', 'name' => 'Unboundbyte Solutions Private Limited', 'sameAs' => url('/')],
                ],
            ),
        ]);
    }

    /** The page a training and placement officer is sent to. */
    public function forColleges(): Response
    {
        return Inertia::render('marketing/ForColleges', [
            'internships' => Course::query()
                ->publiclyVisible()
                ->internships()
                ->orderBy('sort_order')
                ->get(['title', 'slug', 'duration_weeks', 'duration_months'])
                ->map(fn (Course $course) => [
                    'title' => $course->title,
                    'slug' => $course->slug,
                    'durationLabel' => $course->durationLabel(),
                ]),
            'seo' => Seo::for(
                'For colleges — internship and training tie-ups',
                'Send a batch of students on a structured internship. A signed memorandum, a named coordinator, batch enrolment, and progress reports your department can use.',
                '/for-colleges',
            ),
        ]);
    }
}

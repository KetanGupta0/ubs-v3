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
 * Public training pages.
 *
 * Every query goes through `publiclyVisible`, so a programme marked as visible
 * only inside the learning management system can never appear here, and its
 * URL returns a 404 rather than a preview.
 */
class TrainingController extends Controller
{
    public function index(Request $request): Response
    {
        $level = $request->query('level') ?: null;
        $type = $request->query('type') ?: null;

        $courses = Course::query()
            ->publiclyVisible()
            ->taught()
            ->with('batches')
            ->when($level, fn (Builder $q, string $value) => $q->where('level', $value))
            ->when($type, fn (Builder $q, string $value) => $q->where('type', $value))
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('marketing/training/Index', [
            'courses' => $courses->map->toCardArray()->values(),
            'filters' => ['level' => $level, 'type' => $type],
            'levels' => Course::query()->publiclyVisible()->taught()->distinct()->orderBy('level')->pluck('level'),
            'totalCount' => Course::query()->publiclyVisible()->taught()->count(),
            'seo' => Seo::for(
                'Live training — programmes taught by working developers',
                'Live technical training on Google Meet, with attendance, assessment, projects and a certificate that can be verified. Full stack, Laravel, Vue, Python and databases.',
                '/training',
                Seo::breadcrumbs([
                    ['label' => 'Home', 'href' => '/'],
                    ['label' => 'Training', 'href' => '/training'],
                ]),
            ),
        ]);
    }

    public function show(Course $course): Response
    {
        // Not publicly visible, or an internship, must not resolve here. An
        // internship has its own page, and the two listings must not bleed.
        abort_unless(
            $course->is_published && $course->visibility === 'public' && ! $course->isInternship(),
            404,
        );

        $batches = $course->batches()
            ->where('is_published', true)
            ->whereIn('status', ['upcoming', 'running'])
            ->orderBy('starts_on')
            ->get();

        return Inertia::render('marketing/training/Show', [
            'course' => [
                ...$course->toCardArray(),
                'description' => $course->description,
                'syllabus' => $course->syllabus ?? [],
                'outcomes' => $course->outcomes ?? [],
                'prerequisites' => $course->prerequisites ?? [],
                'audience' => $course->audience ?? [],
                'tools' => $course->tools ?? [],
            ],
            'batches' => $batches->map->toPublicArray()->values(),
            'related' => Course::query()
                ->publiclyVisible()
                ->taught()
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
                "/training/{$course->slug}",
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
}

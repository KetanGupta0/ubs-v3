<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\TeamMember;
use App\Support\Preview;
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
            'mentors' => TeamMember::query()->mentors()->get()->map->toPublicArray()->values(),
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
        // A course kept inside the LMS has no public page for anybody;
        // an unpublished one opens only as a preview for staff.
        abort_unless($course->visibility === 'public' && ! $course->isInternship(), 404);
        $previewing = Preview::unpublished($course->is_published);

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
            'reviews' => $this->publicReviews($course),
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
            'seo' => Preview::seo(Seo::for(
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
            ), $previewing),
        ]);
    }

    /**
     * What students said, for the public page: approved reviews whose authors
     * agreed to the public site, and an average of exactly those, so the
     * figure and the reviews under it always match.
     *
     * @return array{count: int, average: float|null, items: array<int, array<string, mixed>>}
     */
    protected function publicReviews(Course $course): array
    {
        $reviews = CourseReview::query()
            ->where('course_id', $course->id)
            ->public()
            ->with('student:id,name')
            ->latest()
            ->get();

        return [
            'count' => $reviews->count(),
            'average' => $reviews->isEmpty() ? null : round($reviews->avg('rating'), 1),
            'items' => $reviews->take(6)->map->toPublicArray()->values()->all(),
        ];
    }
}

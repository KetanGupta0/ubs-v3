<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Faq;
use App\Models\Service;
use App\Models\Solution;
use App\Models\SolutionCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The content the public site renders.
 *
 * Safe to re-run: everything is matched on its slug and updated in place, so a
 * copy edit lands without duplicating rows or losing anything an administrator
 * changed in a different field.
 */
class CatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSolutions();
        $this->seedServices();
        $this->seedCourses();
        $this->seedFaqs();
    }

    /**
     * Turn the two character sequence "\n" into a real newline.
     *
     * The content files use single quoted PHP strings, which is the safe choice
     * for copy full of apostrophes and currency symbols, but it means escape
     * sequences arrive literally. Paragraph breaks are converted here rather
     * than in the view, so what is stored is what gets rendered.
     */
    protected function paragraphs(?string $text): ?string
    {
        return $text === null ? null : str_replace(['\\n\\n', '\\n'], ["\n\n", "\n"], $text);
    }

    protected function seedSolutions(): void
    {
        $data = require database_path('data/solutions.php');

        foreach ($data['categories'] as $category) {
            SolutionCategory::query()->updateOrCreate(['slug' => $category['slug']], $category);
        }

        $categories = SolutionCategory::query()->pluck('id', 'slug');

        foreach ($data['solutions'] as $index => $solution) {
            $slug = $solution['slug'] ?? Str::slug($solution['title']);
            $categorySlug = $solution['category'];
            unset($solution['category']);

            Solution::query()->updateOrCreate(['slug' => $slug], [
                ...$solution,
                'slug' => $slug,
                'description' => $this->paragraphs($solution['description'] ?? null),
                'solution_category_id' => $categories[$categorySlug],
                'sort_order' => $index,
                'is_published' => true,
                'seo' => [
                    'title' => $solution['title'].' — custom software by Unboundbyte',
                    'description' => Str::limit($solution['summary'], 155),
                ],
            ]);
        }

        $this->command?->info('Seeded '.count($data['solutions']).' solutions in '.count($data['categories']).' categories.');
    }

    protected function seedServices(): void
    {
        foreach (require database_path('data/services.php') as $service) {
            Service::query()->updateOrCreate(['slug' => $service['slug']], [
                ...$service,
                'description' => $this->paragraphs($service['description'] ?? null),
                'is_published' => true,
                'seo' => [
                    'title' => $service['title'].' — Unboundbyte Solutions',
                    'description' => Str::limit($service['summary'], 155),
                ],
            ]);
        }

        $this->command?->info('Seeded 3 services.');
    }

    protected function seedCourses(): void
    {
        $courses = require database_path('data/courses.php');

        foreach ($courses as $course) {
            $record = Course::query()->updateOrCreate(['slug' => $course['slug']], [
                ...$course,
                'description' => $this->paragraphs($course['description'] ?? null),
                'visibility' => 'public',
                'is_published' => true,
                'seo' => [
                    'title' => $course['title'].' — live training by Unboundbyte',
                    'description' => Str::limit($course['summary'], 155),
                ],
            ]);

            $this->seedBatchesFor($record);
        }

        // One programme that exists only inside the learning management system,
        // so the public listing has something it must deliberately exclude.
        Course::query()->updateOrCreate(['slug' => 'internal-onboarding-track'], [
            'title' => 'Internal onboarding track',
            'type' => 'programme',
            'tagline' => 'For cohorts enrolled directly by us',
            'summary' => 'Runs inside the learning management system only. It is never advertised publicly.',
            'level' => 'beginner',
            'duration_weeks' => 4,
            'hours_per_week' => 3,
            'price' => 0,
            'visibility' => 'lms_only',
            'is_published' => true,
        ]);

        $this->command?->info('Seeded '.count($courses).' public courses plus one that is LMS only.');
    }

    /** Two upcoming batches per course, so the public pages have real dates. */
    protected function seedBatchesFor(Course $course): void
    {
        $schedules = [
            [['day' => 'Mon', 'from' => '19:30', 'to' => '21:00'], ['day' => 'Thu', 'from' => '19:30', 'to' => '21:00']],
            [['day' => 'Sat', 'from' => '10:00', 'to' => '13:00']],
        ];

        foreach ([0, 1] as $offset) {
            $starts = now()->addWeeks(2 + ($offset * 6))->startOfWeek();

            Batch::query()->updateOrCreate(
                ['code' => strtoupper(Str::of($course->slug)->limit(6, '')->replace('-', '')).'-'.$starts->format('My')],
                [
                    'course_id' => $course->id,
                    'name' => $starts->format('F Y').' batch',
                    'starts_on' => $starts,
                    'ends_on' => $starts->copy()->addWeeks($course->duration_weeks ?? 8),
                    'schedule' => $schedules[$offset],
                    'capacity' => $offset === 0 ? 24 : 30,
                    'seats_taken' => $offset === 0 ? 20 : 4,
                    'status' => 'upcoming',
                    'is_published' => true,
                ],
            );
        }
    }

    protected function seedFaqs(): void
    {
        foreach (require database_path('data/faqs.php') as $index => $faq) {
            Faq::query()->updateOrCreate(
                ['question' => $faq['question']],
                [...$faq, 'sort_order' => $index, 'is_published' => true],
            );
        }
    }
}

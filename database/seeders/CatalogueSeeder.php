<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Faq;
use App\Models\Service;
use App\Models\Solution;
use App\Models\SolutionCategory;
use App\Support\Money;
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
        $this->seedInternships();
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
                // The content files quote fees in rupees, which is how a human
                // writes them. Everything past this line is paise.
                'price' => Money::toPaise($course['price'] ?? 0),
                'sale_price' => isset($course['sale_price']) ? Money::toPaise($course['sale_price']) : null,
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

    /**
     * Internships, stored as courses with `type` set to internship.
     *
     * Structurally an internship is the same object: a cohort with a schedule,
     * a syllabus, a mentor and an assessment. What differs is the paperwork it
     * produces, which is why those columns exist rather than a second table.
     */
    protected function seedInternships(): void
    {
        $internships = require database_path('data/internships.php');

        foreach ($internships as $internship) {
            $record = Course::query()->updateOrCreate(['slug' => $internship['slug']], [
                ...$internship,
                'type' => 'internship',
                'price' => Money::toPaise($internship['price'] ?? 0),
                'sale_price' => isset($internship['sale_price']) ? Money::toPaise($internship['sale_price']) : null,
                'description' => $this->paragraphs($internship['description'] ?? null),
                'visibility' => 'public',
                'is_published' => true,
                'seo' => [
                    'title' => $internship['title'].' — for college students, by Unboundbyte',
                    'description' => Str::limit($internship['summary'], 150),
                ],
            ]);

            $this->seedBatchesFor($record);
        }

        $this->command?->info('Seeded '.count($internships).' internships.');
    }

    /** Two upcoming batches per course, so the public pages have real dates. */
    protected function seedBatchesFor(Course $course): void
    {
        $schedules = [
            [['day' => 'Mon', 'from' => '19:30', 'to' => '21:00'], ['day' => 'Thu', 'from' => '19:30', 'to' => '21:00']],
            [['day' => 'Sat', 'from' => '10:00', 'to' => '13:00']],
        ];

        $gap = $course->type === 'internship' ? 4 : 6;

        /*
         * Fill levels vary per offering rather than being the same everywhere.
         * A "only 4 seats left" badge on every single card reads as a sales
         * trick, which is exactly the impression the rest of this site is
         * trying not to give. Derived from the slug so it is stable across
         * re-seeds instead of shuffling on every run.
         */
        $seed = crc32($course->slug);

        foreach ([0, 1] as $offset) {
            $starts = now()->addWeeks(2 + ($offset * $gap))->startOfWeek();

            $capacity = $offset === 0 ? 24 : 30;

            // The nearer batch fills between a third and nearly full; the later
            // one has barely opened.
            $taken = $offset === 0
                ? (int) round($capacity * (0.35 + (($seed % 60) / 100)))
                : (int) ($seed % 7);

            Batch::query()->updateOrCreate(
                ['code' => strtoupper(Str::of($course->slug)->limit(6, '')->replace('-', '')).'-'.$starts->format('My')],
                [
                    'course_id' => $course->id,
                    'name' => $starts->format('F Y').' batch',
                    'starts_on' => $starts,
                    'ends_on' => $starts->copy()->addWeeks($course->duration_weeks ?? 8),
                    'schedule' => $schedules[$offset],
                    'capacity' => $capacity,
                    'seats_taken' => min($taken, $capacity),
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

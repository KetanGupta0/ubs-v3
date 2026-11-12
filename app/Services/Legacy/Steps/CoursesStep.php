<?php

namespace App\Services\Legacy\Steps;

use App\Models\Course;
use App\Models\Redirect;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * v2 training programs → v3 courses.
 *
 * A program the course map points at an existing v3 course is mapped onto it,
 * and the v3 course is left as it is: the catalogue copy was written for v3.
 * Every other program becomes a new course, as a draft for a person to read
 * before it is published, except one v2 kept private or inactive, which
 * stays inside the LMS where its students already are.
 */
class CoursesStep extends Step
{
    public function name(): string
    {
        return 'courses';
    }

    public function sourceTables(): array
    {
        return ['training_programs'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('training_programs')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('courses', 'read');
            $source = "training_programs#{$row->id}";
            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('training_programs', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('courses', 'unchanged');

                return;
            }

            $mapping = $context->courseMap[$row->slug] ?? null;

            if (is_string($mapping)) {
                $course = Course::query()->where('slug', $mapping)->first();

                if (! $course) {
                    $report->count('courses', 'skipped');
                    $report->warn('courses', 'map_target_missing', $source, "The course map points at {$mapping}, which v3 does not have; not imported.");

                    return;
                }

                $context->ledger->record('training_programs', (int) $row->id, $course, $checksum);
                $this->redirect($row->slug, $course);
                $report->count('courses', 'updated');

                return;
            }

            $course = $entry ? Course::query()->find($entry->target_id) : null;
            $isNew = $course === null;
            $private = ! $row->is_public || ! $row->is_active;
            $type = $mapping['type'] ?? null;

            if (! $type) {
                $type = Str::contains(Str::lower($row->title), 'intern') ? 'internship' : 'course';

                if ($isNew) {
                    $report->warn('courses', 'type_guessed', $source, "Imported as a {$type}, judged from its title; set its type in the course map if that is wrong.");
                }
            }

            $course ??= new Course(['slug' => $this->slug($row->slug)]);
            $summary = trim(strip_tags((string) ($row->short_description ?? '')));

            $course->fill([
                'title' => $row->title,
                'type' => in_array($type, ['internship', 'course', 'programme'], true) ? $type : 'course',
                'tagline' => Str::limit($summary ?: $row->title, 250, ''),
                'summary' => $summary ?: $row->title,
                'description' => $row->description,
                'level' => $this->level($row->level ?? null),
                'price' => Money::toPaise($row->price ?? 0),
                'visibility' => $private ? 'lms_only' : 'public',
                'syllabus' => $this->syllabus($row->curriculum ?? null),
                'sort_order' => (int) ($row->sort_order ?? 0),
                ...$this->duration($row->duration ?? null),
            ]);

            if ($isNew) {
                // A person reads it before visitors do.
                $course->is_published = false;
                $course->is_enrollment_open = (bool) ($row->is_enrollment_open ?? true);

                if (! $private) {
                    $report->warn('courses', 'draft_for_review', $source, 'Imported as a draft; read it and publish it in the catalogue, or map it onto a v3 course.');
                }
            }

            if ($course->exists && Course::query()->where('slug', $course->slug)->whereKeyNot($course->id)->exists()) {
                $course->slug = $this->slug($row->slug);
            }

            $course->save();
            $this->redirect($row->slug, $course);
            $context->ledger->record('training_programs', (int) $row->id, $course, $checksum);
            $report->count('courses', $isNew ? 'created' : 'updated');
        });
    }

    /**
     * The old site served every program at /training/{slug}. Where the course
     * now lives somewhere else, that address is sent on, so old links and
     * search results keep working.
     */
    protected function redirect(string $oldSlug, Course $course): void
    {
        $from = '/training/'.$oldSlug;
        $to = ($course->isInternship() ? '/internships/' : '/training/').$course->slug;

        if ($from !== $to) {
            Redirect::query()->updateOrCreate(['from_path' => $from], ['to_path' => $to, 'reason' => 'Old site course address']);
        }
    }

    /** v2's slug if v3 does not use it, otherwise the same with -v2. */
    protected function slug(string $slug): string
    {
        $slug = Str::slug($slug) ?: 'imported-course';

        return Course::query()->where('slug', $slug)->exists() ? "{$slug}-v2" : $slug;
    }

    protected function level(?string $level): string
    {
        $level = Str::lower((string) $level);

        return match (true) {
            str_contains($level, 'adv') => 'advanced',
            str_contains($level, 'inter') => 'intermediate',
            default => 'beginner',
        };
    }

    /** "6 months", "8 weeks": kept only when it says which. */
    protected function duration(?string $text): array
    {
        if (! preg_match('/(\d+)\s*(week|month)/i', (string) $text, $match)) {
            return [];
        }

        return str_starts_with(strtolower($match[2]), 'week')
            ? ['duration_weeks' => (int) $match[1]]
            : ['duration_months' => (int) $match[1]];
    }

    /**
     * v2's curriculum, as v3's syllabus shape: modules with topics.
     * Plain strings become one module each.
     */
    protected function syllabus(mixed $curriculum): ?array
    {
        $items = is_string($curriculum) ? json_decode($curriculum, true) : $curriculum;

        if (! is_array($items) || $items === []) {
            return null;
        }

        return collect($items)->map(function ($item) {
            if (is_string($item)) {
                return ['module' => $item, 'topics' => []];
            }

            $item = (array) $item;

            return [
                'module' => (string) ($item['title'] ?? $item['module'] ?? $item['name'] ?? 'Module'),
                'topics' => array_values(array_map('strval', (array) ($item['topics'] ?? $item['items'] ?? []))),
            ];
        })->values()->all();
    }
}

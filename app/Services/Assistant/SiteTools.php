<?php

namespace App\Services\Assistant;

use App\Models\AssistantConversation;
use App\Models\AssistantGap;
use App\Models\Batch;
use App\Models\Course;
use App\Models\LegalDocument;
use App\Models\RegistrationForm;
use App\Models\Service;
use App\Models\Solution;
use App\Support\Identifier;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use stdClass;

/**
 * What the assistant may look at and do (§9.2): read the public catalogue,
 * by the same rules as the public pages, note a question the site does not
 * answer, and put a visitor's details on a card for them to send. Nothing
 * here writes anything a visitor did not ask for; the card is sent by the
 * visitor's own click, never by the model.
 */
class SiteTools
{
    public const KINDS = ['solution', 'service', 'course', 'internship'];

    public const INTERESTS = ['solution', 'service', 'training', 'internship', 'college', 'general'];

    /** Words that match everything and mean nothing to a search. */
    protected const FILLER = ['the', 'and', 'for', 'you', 'your', 'have', 'has', 'with', 'does', 'any', 'are', 'can', 'what', 'which', 'how', 'need', 'want', 'looking', 'about', 'our', 'that', 'this', 'there', 'some', 'something', 'please', 'tell', 'more', 'get', 'kya', 'hai', 'aap', 'mujhe', 'chahiye', 'koi'];

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        $object = fn (array $properties) => [
            'type' => 'object',
            'properties' => $properties === [] ? new stdClass : $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];

        return [
            [
                'name' => 'search_catalogue',
                'description' => 'Search what the company offers: software products (some with a live demo, the source code for sale, or hosted plans), services (building software to order, modernisation, maintenance), courses and internships. Returns up to six matches, each with its page address and its price as the website shows it. Use it before saying anything about what is offered.',
                'inputSchema' => $object([
                    'query' => ['type' => 'string', 'description' => 'A few words about what the visitor wants, for example "school management" or "python course".'],
                    'kind' => ['type' => 'string', 'enum' => ['any', ...self::KINDS]],
                ]),
                'strict' => true,
            ],
            [
                'name' => 'get_item',
                'description' => 'The details of one product, service, course or internship found by search_catalogue: what it is, its price as the website shows it, a live demo if it has one, and for a course or internship its next batches and any open registration form.',
                'inputSchema' => $object([
                    'kind' => ['type' => 'string', 'enum' => self::KINDS],
                    'slug' => ['type' => 'string', 'description' => 'The slug search_catalogue returned.'],
                ]),
                'strict' => true,
            ],
            [
                'name' => 'open_intakes',
                'description' => 'Courses and internships starting soon that can be joined, with their start dates and the real number of seats left where there is a limit, and registration forms that are open now.',
                'inputSchema' => $object([]),
                'strict' => true,
            ],
            [
                'name' => 'site_pages',
                'description' => 'The main pages of the website and their addresses, including the contact page.',
                'inputSchema' => $object([]),
                'strict' => true,
            ],
            [
                'name' => 'record_gap',
                'description' => 'Call this when the visitor asks something the other tools cannot answer, before saying you do not know. It tells the team which question the website does not answer. Write the question briefly, without the visitor\'s name or contact details.',
                'inputSchema' => $object([
                    'question' => ['type' => 'string'],
                ]),
                'strict' => true,
            ],
            [
                'name' => 'offer_contact',
                'description' => 'Only when the visitor has said they want the team to contact them and has given their name and email: show them a card with these details and a Send button. Nothing reaches the team unless the visitor presses Send themselves. Never call it without asking them first.',
                'inputSchema' => $object([
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'phone' => ['type' => 'string', 'description' => 'With the country code, or an empty string if not given.'],
                    'need' => ['type' => 'string', 'description' => 'What they want, in a sentence or two, in their own words where possible.'],
                    'interest' => ['type' => 'string', 'enum' => self::INTERESTS],
                ]),
                'strict' => true,
            ],
        ];
    }

    /**
     * Run one tool. Whatever comes back is sent to the model as data.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(string $name, array $input, AssistantConversation $conversation): array
    {
        return match ($name) {
            'search_catalogue' => $this->search((string) ($input['query'] ?? ''), (string) ($input['kind'] ?? 'any')),
            'get_item' => $this->item((string) ($input['kind'] ?? ''), (string) ($input['slug'] ?? '')),
            'open_intakes' => $this->intakes(),
            'site_pages' => $this->pages(),
            'record_gap' => $this->gap($conversation, (string) ($input['question'] ?? '')),
            'offer_contact' => $this->offer($conversation, $input),
            default => ['error' => 'There is no such tool.'],
        };
    }

    /* ----------------------------------------------------------- reading */

    /** @return array<string, mixed> */
    protected function search(string $query, string $kind): array
    {
        $words = collect(preg_split('/[^\pL\pN]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $word) => mb_strlen($word) >= 3 && ! in_array($word, self::FILLER, true))
            ->unique()
            ->take(6)
            ->values();

        if ($words->isEmpty()) {
            return ['results' => [], 'note' => 'Nothing to search for.'];
        }

        $kinds = $kind === 'any' || ! in_array($kind, self::KINDS, true) ? self::KINDS : [$kind];

        $found = collect($kinds)->flatMap(fn (string $one) => $this->matching($one, $words))
            ->sortByDesc('score')
            ->take(6)
            ->map(fn (array $row) => collect($row)->except('score')->all())
            ->values();

        return ['results' => $found->all(), 'note' => $found->isEmpty() ? 'Nothing on the site matches; say so, and offer the contact page.' : null];
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function matching(string $kind, Collection $words): Collection
    {
        $like = fn (Builder $query, array $columns) => $query->where(function (Builder $any) use ($words, $columns) {
            foreach ($words as $word) {
                $escaped = '%'.addcslashes($word, '%_\\').'%';

                foreach ($columns as $column) {
                    $any->orWhere($column, 'like', $escaped);
                }
            }
        });

        $rows = match ($kind) {
            'solution' => $like(Solution::query()->published()->with('tiers'), ['title', 'tagline', 'summary'])->limit(20)->get()
                ->map(fn (Solution $solution) => $this->solutionRow($solution)),
            'service' => $like(Service::query()->published(), ['title', 'tagline', 'summary'])->limit(20)->get()
                ->map(fn (Service $service) => $this->serviceRow($service)),
            'course' => $like(Course::query()->publiclyVisible()->taught(), ['title', 'tagline', 'summary'])->limit(20)->get()
                ->map(fn (Course $course) => $this->courseRow($course)),
            'internship' => $like(Course::query()->publiclyVisible()->internships(), ['title', 'tagline', 'summary'])->limit(20)->get()
                ->map(fn (Course $course) => $this->courseRow($course)),
            default => collect(),
        };

        // More of the words in the title first.
        return $rows->map(function (array $row) use ($words) {
            $title = mb_strtolower($row['title']);

            return $row + ['score' => $words->filter(fn (string $word) => str_contains($title, $word))->count() * 2
                + $words->filter(fn (string $word) => str_contains(mb_strtolower($row['summary'] ?? ''), $word))->count()];
        });
    }

    /** @return array<string, mixed> */
    protected function item(string $kind, string $slug): array
    {
        $none = ['error' => 'Not found on the website. Search again, or offer the contact page.'];

        if ($kind === 'solution') {
            $solution = Solution::query()->published()->with('tiers')->where('slug', $slug)->first();

            return $solution ? $this->solutionRow($solution) + [
                'description' => $this->words($solution->description, 1200),
                'features' => collect($solution->features ?? [])->map(fn ($feature) => is_array($feature) ? ($feature['title'] ?? $feature['name'] ?? null) : $feature)->filter()->take(8)->values()->all(),
                'timeline' => $solution->offers('custom_build') ? $solution->timeline() : null,
                'plans_and_licences' => $solution->tiers->where('is_active', true)->map(fn ($tier) => [
                    'name' => $tier->name,
                    'kind' => $tier->kind === 'plan' ? 'hosted plan' : 'source code licence',
                    'price' => Money::price($tier->price).' + GST',
                ])->values()->all(),
            ] : $none;
        }

        if ($kind === 'service') {
            $service = Service::query()->published()->where('slug', $slug)->first();

            return $service ? $this->serviceRow($service) + [
                'description' => $this->words($service->description, 1200),
                'deliverables' => array_slice($service->deliverables ?? [], 0, 8),
                'ways_of_working' => collect($service->engagement_models ?? [])->pluck('name')->filter()->values()->all(),
            ] : $none;
        }

        if (in_array($kind, ['course', 'internship'], true)) {
            $course = Course::query()->publiclyVisible()
                ->when($kind === 'internship', fn ($query) => $query->internships(), fn ($query) => $query->taught())
                ->where('slug', $slug)
                ->first();

            return $course ? $this->courseRow($course) + [
                'level' => $course->level,
                'duration' => $course->durationLabel(),
                'mode' => $course->mode,
                'batches' => $this->batchesOf($course),
                'registration_forms' => $this->formsOf($course),
            ] : $none;
        }

        return $none;
    }

    /** @return array<string, mixed> */
    protected function intakes(): array
    {
        $batches = Batch::query()
            ->where('is_published', true)
            ->where('status', 'upcoming')
            ->whereDate('starts_on', '>=', today())
            ->whereHas('course', fn (Builder $query) => $query->publiclyVisible())
            ->with('course')
            ->orderBy('starts_on')
            ->limit(10)
            ->get()
            ->map(fn (Batch $batch) => [
                'course' => $batch->course->title,
                'kind' => $batch->course->isInternship() ? 'internship' : 'course',
                'batch' => $batch->name,
                'starts' => $batch->starts_on->format('j M Y'),
                'seats_left' => $batch->seatsLeft(),
                'url' => $this->courseUrl($batch->course),
            ]);

        return ['batches' => $batches->all(), 'registration_forms' => $this->formsOf(null)];
    }

    /** @return array<string, mixed> */
    protected function pages(): array
    {
        $legal = LegalDocument::query()->get()
            ->filter(fn (LegalDocument $document) => $document->versions()->where('status', 'published')->exists())
            ->map(fn (LegalDocument $document) => ['title' => $document->title, 'url' => "/legal/{$document->key}"])
            ->values();

        return ['pages' => [
            ['title' => 'Home', 'url' => '/'],
            ['title' => 'Software products', 'url' => '/solutions'],
            ['title' => 'Services', 'url' => '/services'],
            ['title' => 'Courses', 'url' => '/training'],
            ['title' => 'Internships', 'url' => '/internships'],
            ['title' => 'About us', 'url' => '/about'],
            ['title' => 'Contact us (write to the team)', 'url' => '/contact'],
            ...$legal->all(),
        ]];
    }

    /* ------------------------------------------------------------ writing */

    /** @return array<string, mixed> */
    protected function gap(AssistantConversation $conversation, string $question): array
    {
        $question = Str::limit(trim($question), 290);

        if ($question !== '' && $conversation->gaps()->count() < 10) {
            AssistantGap::query()->create(['assistant_conversation_id' => $conversation->id, 'question' => $question, 'created_at' => now()]);
        }

        return ['noted' => true];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function offer(AssistantConversation $conversation, array $input): array
    {
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $name = Str::limit(trim((string) ($input['name'] ?? '')), 120, '');

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['shown' => false, 'error' => 'A name and a complete email address are needed. Ask the visitor for them.'];
        }

        $phone = trim((string) ($input['phone'] ?? ''));

        $conversation->forceFill(['lead_offer' => [
            'name' => $name,
            'email' => $email,
            'mobile' => $phone !== '' ? Identifier::normaliseMobile($phone) : null,
            'need' => Str::limit(trim((string) ($input['need'] ?? '')), 1000, ''),
            'interest' => in_array($input['interest'] ?? null, self::INTERESTS, true) ? $input['interest'] : 'general',
        ]])->save();

        return ['shown' => true, 'note' => 'The visitor now sees a card with these details and a Send button. Nothing is sent until they press it. Tell them to check the details and press Send if they are right.'];
    }

    /* ------------------------------------------------------------- shapes */

    /** @return array<string, mixed> */
    protected function solutionRow(Solution $solution): array
    {
        $from = $solution->fromPrice();

        return [
            'kind' => 'solution',
            'title' => $solution->title,
            'slug' => $solution->slug,
            'summary' => Str::limit((string) ($solution->tagline ?: $solution->summary), 220),
            'url' => "/solutions/{$solution->slug}",
            'price' => array_filter([
                'built_to_order' => $solution->offers('custom_build') && $solution->priceBand()
                    ? $solution->priceBand().', indicative; settled after a conversation about what is needed'
                    : null,
                'fixed_price' => $from !== null ? 'from '.Money::price($from).' + GST' : null,
            ]) ?: 'No price on the website; the team quotes after a conversation.',
            'live_demo' => $solution->offers('demo'),
        ];
    }

    /** @return array<string, mixed> */
    protected function serviceRow(Service $service): array
    {
        return [
            'kind' => 'service',
            'title' => $service->title,
            'slug' => $service->slug,
            'summary' => Str::limit((string) ($service->tagline ?: $service->summary), 220),
            'url' => "/services/{$service->slug}",
            'price' => 'No fixed price; the team quotes after a conversation.',
        ];
    }

    /** @return array<string, mixed> */
    protected function courseRow(Course $course): array
    {
        return [
            'kind' => $course->isInternship() ? 'internship' : 'course',
            'title' => $course->title,
            'slug' => $course->slug,
            'summary' => Str::limit((string) ($course->tagline ?: $course->summary), 220),
            'url' => $this->courseUrl($course),
            'fee' => $course->isFree() ? 'Free' : Money::display($course->effectivePrice()).', GST included',
            'enrolment_open' => (bool) $course->is_enrollment_open,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function batchesOf(Course $course): array
    {
        return $course->batches()
            ->where('is_published', true)
            ->whereIn('status', ['upcoming', 'running'])
            ->orderBy('starts_on')
            ->limit(5)
            ->get()
            ->map(fn (Batch $batch) => [
                'name' => $batch->name,
                'starts' => $batch->starts_on?->format('j M Y'),
                'status' => $batch->status,
                'seats_left' => $batch->seatsLeft(),
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function formsOf(?Course $course): array
    {
        return RegistrationForm::query()
            ->where('is_active', true)
            ->when($course, fn ($query) => $query->where('course_id', $course->id))
            // A form for a course only the LMS shows is shared with a group directly; the assistant does not announce it.
            ->whereHas('course', fn (Builder $query) => $query->publiclyVisible())
            ->with('course')
            ->limit(20)
            ->get()
            ->filter(fn (RegistrationForm $form) => $form->isOpen())
            ->take(10)
            ->map(fn (RegistrationForm $form) => [
                'title' => $form->title,
                'course' => $form->course->title,
                'fee' => $form->feeLabel(),
                'url' => "/join/{$form->slug}",
            ])->values()->all();
    }

    protected function courseUrl(Course $course): string
    {
        return ($course->isInternship() ? '/internships/' : '/training/').$course->slug;
    }

    protected function words(?string $text, int $limit): ?string
    {
        $plain = Str::of(strip_tags((string) $text))->squish()->toString();

        return $plain === '' ? null : Str::limit($plain, $limit);
    }
}

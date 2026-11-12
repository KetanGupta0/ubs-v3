<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\RegistrationForm;
use App\Services\Lms\Registrar;
use App\Support\HumanCheck;
use App\Support\Identifier;
use App\Support\Markdown;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A registration form anybody can fill, for one course, without an account.
 *
 * Whatever the address turns out to be, the answer is the same redirect with
 * the same message: "Thanks, check your email." What happens next is
 * Registrar's business and arrives by email, never on this page.
 *
 * The forms are campaign pages (a poster, a college visit), so they are kept
 * out of search and out of the sitemap, and a course that is LMS only can
 * have one without appearing anywhere else on the site.
 */
class RegistrationController extends Controller
{
    public function show(string $slug): Response
    {
        $form = $this->form($slug);
        $course = $form->course;
        $batch = $form->batch;

        return Inertia::render('marketing/Registration', [
            'form' => [
                'slug' => $form->slug,
                'title' => $form->title,
                'intro' => filled($form->intro) ? Markdown::html($form->intro) : null,
                'state' => $form->state(),
                'opensAt' => $form->opens_at?->format('j M Y, g:i a'),
                'closesAt' => $form->closes_at?->format('j M Y, g:i a'),
                'placesLeft' => $form->capacity ? $form->placesLeft() : null,
                'fee' => $form->feeLabel(),
                'reviewed' => $form->approval === 'review',
                'fields' => collect($form->standardFields())
                    ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                    ->values(),
                'questions' => $form->questionList(),
            ],
            'course' => [
                'title' => $course->title,
                'tagline' => $course->tagline,
                'type' => $course->type,
                'level' => $course->level,
                'weeks' => $course->duration_weeks,
            ],
            'batch' => $batch ? [
                'name' => $batch->name,
                'startsOn' => $batch->starts_on?->format('j M Y'),
                'schedule' => $batch->schedule ?? [],
            ] : null,
            'humanCheck' => HumanCheck::siteKey(),
            'seo' => [
                ...Seo::for(
                    "Register: {$form->title}",
                    $course->tagline ?: "Register for {$course->title}.",
                    $form->path(),
                ),
                'noindex' => true,
            ],
        ]);
    }

    public function store(Request $request, string $slug, Registrar $registrar): RedirectResponse
    {
        $form = $this->form($slug);

        if (! $form->isOpen()) {
            return redirect($form->path());
        }

        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'mobile' => $request->filled('mobile') ? Identifier::normaliseMobile((string) $request->input('mobile')) : null,
        ]);

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['required', 'string', 'regex:/^\+\d{10,15}$/'],
            // A field a person never sees and a crude bot usually fills.
            'website' => ['prohibited'],
        ];

        foreach (array_keys($form->standardFields()) as $key) {
            $rules["details.{$key}"] = $key === 'graduation_year'
                ? ['required', 'integer', 'min:1980', 'max:'.(now()->year + 8)]
                : ['required', 'string', 'max:160'];
        }

        foreach ($form->questionList() as $question) {
            $rules["answers.{$question['key']}"] = [
                $question['required'] ? 'required' : 'nullable',
                ...match ($question['type']) {
                    'short' => ['string', 'max:300'],
                    'long' => ['string', 'max:3000'],
                    'choice' => [Rule::in($question['options'] ?? [])],
                    'yesno' => [Rule::in(['yes', 'no'])],
                    default => ['prohibited'],
                },
            ];
        }

        $validated = $this->validatedInput($request, $rules, [
            'mobile.regex' => 'Enter a valid mobile number, including the country code.',
            'website.prohibited' => 'That submission could not be accepted.',
            'details.*.required' => 'Fill this in.',
            'answers.*.required' => 'Answer this one.',
            'answers.*.in' => 'Choose one of the answers given.',
        ]);

        if (! HumanCheck::passes($request)) {
            throw ValidationException::withMessages([
                'human' => 'Please complete the check that you are a person, then send it again.',
            ]);
        }

        $registrar->receive($form, [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'mobile' => $validated['mobile'],
            'details' => collect(array_keys($form->standardFields()))
                ->mapWithKeys(fn (string $key) => [$key => trim((string) data_get($validated, "details.{$key}"))])
                ->all(),
            'answers' => collect($form->questionList())
                ->mapWithKeys(fn (array $question) => [$question['key'] => trim((string) data_get($validated, "answers.{$question['key']}"))])
                ->filter(fn (string $answer) => $answer !== '')
                ->all(),
        ]);

        // Identical for a new address, a known one and a repeat.
        return redirect($form->path())->with('registrationSent', true);
    }

    protected function form(string $slug): RegistrationForm
    {
        return RegistrationForm::query()
            ->with('course', 'batch')
            ->where('slug', $slug)
            ->firstOrFail();
    }
}

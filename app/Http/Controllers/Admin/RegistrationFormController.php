<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Registration;
use App\Models\RegistrationForm;
use App\Services\Admin\Auditor;
use App\Services\Lms\Registrar;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Registration forms, and what came in through them.
 *
 * Building a form is here; what a registration does to an account is
 * Registrar, so this screen cannot enrol somebody the rules would not.
 */
class RegistrationFormController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(RegistrationForm::query()
            ->with('course:id,title,is_published', 'batch:id,name')
            ->withCount([
                'registrations',
                'registrations as waiting_count' => fn (Builder $query) => $query->where('status', 'awaiting_review'),
            ]))
            ->searchable(['title', 'slug'])
            ->defaultSort('-created_at')
            ->exportName('registration-forms')
            ->columns([
                Column::make('title', 'Form')->sortable(),
                Column::make('course', 'Course'),
                Column::make('state', 'Now'),
                Column::make('registrations_count', 'Registrations')->numeric()->sortable(),
                Column::make('waiting_count', 'To review')->numeric()->sortable(),
                Column::make('closes_at', 'Closes')->sortable(),
            ])
            ->filters([
                Filter::select('course_id', $this->courseOptions()->pluck('label', 'value')->all(), 'Course')->placeholder('Any course'),
            ])
            ->transform(fn (RegistrationForm $form) => [
                'id' => $form->id,
                'title' => $form->title,
                'course' => $form->course->title.($form->batch ? " · {$form->batch->name}" : ''),
                'state' => $form->state(),
                'registrations_count' => $form->registrations_count,
                'waiting_count' => $form->waiting_count,
                'closes_at' => $form->closes_at?->format('j M Y') ?? 'Open ended',
                'path' => $form->path(),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/registrations/Index', [
            'table' => $table->toArray($request),
            'waiting' => Registration::query()->where('status', 'awaiting_review')->count(),
            'canManage' => $request->user()->hasPermission('students.update'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/registrations/Form', $this->formOptions(null));
    }

    public function edit(RegistrationForm $form): Response
    {
        return Inertia::render('admin/registrations/Form', $this->formOptions($form));
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $form = new RegistrationForm(['created_by' => $request->user()->id]);
        $this->fill($request, $form);
        $form->save();
        $auditor->created($form, $form->title);

        return redirect()
            ->route('admin.registration-forms.show', $form)
            ->with('success', "Created. It is at {$form->path()}; the QR code below opens it.");
    }

    public function update(Request $request, RegistrationForm $form, Auditor $auditor): RedirectResponse
    {
        $this->fill($request, $form);
        $auditor->updated($form, label: $form->title);
        $form->save();

        return redirect()->route('admin.registration-forms.show', $form)->with('success', 'Saved.');
    }

    public function destroy(RegistrationForm $form, Auditor $auditor): RedirectResponse
    {
        // What people sent is a record of what they asked for. A form that
        // has any is closed instead.
        if ($form->registrations()->exists()) {
            return back()->with('error', 'People have registered through this form, so it cannot be deleted. Switch it off instead.');
        }

        $auditor->deleted($form, $form->title);
        $form->delete();

        return redirect()->route('admin.registration-forms.index')->with('success', 'Form deleted.');
    }

    /** The form, its link and QR code, and everybody who registered through it. */
    public function show(Request $request, RegistrationForm $form): Response|HttpResponse
    {
        $form->load('course', 'batch');
        $table = $this->registrationsTable($form);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/registrations/Show', [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'course' => $form->course->title,
                'courseId' => $form->course_id,
                'batch' => $form->batch?->name,
                'state' => $form->state(),
                'url' => $form->url(),
                'qr' => 'data:image/svg+xml;base64,'.base64_encode($this->qr($form)),
                'fee' => $form->feeLabel(),
                'approval' => $form->approval,
                'opensAt' => $form->opens_at?->format('j M Y, g:i a'),
                'closesAt' => $form->closes_at?->format('j M Y, g:i a'),
                'capacity' => $form->capacity,
                'placesTaken' => $form->placesTaken(),
                'questions' => $form->questionList(),
                'fields' => $form->standardFields(),
            ],
            'counts' => Registration::query()
                ->where('registration_form_id', $form->id)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'table' => $table->toArray($request),
            'batches' => Batch::query()
                ->where('course_id', $form->course_id)
                ->orderByDesc('starts_on')
                ->get(['id', 'name', 'capacity', 'seats_taken'])
                ->map(fn (Batch $batch) => [
                    'value' => $batch->id,
                    'label' => $batch->name.($batch->seatsLeft() !== null ? " ({$batch->seatsLeft()} seats left)" : ''),
                ]),
            'canManage' => $request->user()->hasPermission('students.update'),
        ]);
    }

    /** The QR code on its own, to print on a poster. */
    public function qrCode(RegistrationForm $form): HttpResponse
    {
        return response($this->qr($form, 1024), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$form->slug.'-qr.svg"',
        ]);
    }

    /* ------------------------------------------------------- decisions */

    public function approve(Request $request, RegistrationForm $form, Registration $registration, Registrar $registrar): RedirectResponse
    {
        $this->belongs($form, $registration);

        try {
            $registrar->approve($registration, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$registration->name} is on the course and has been told.");
    }

    public function reject(Request $request, RegistrationForm $form, Registration $registration, Registrar $registrar): RedirectResponse
    {
        $this->belongs($form, $registration);

        $validated = $this->validatedInput($request, [
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $registrar->reject($registration, $request->user(), $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Rejected.');
    }

    public function move(Request $request, RegistrationForm $form, Registration $registration, Registrar $registrar): RedirectResponse
    {
        $this->belongs($form, $registration);

        $validated = $this->validatedInput($request, [
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')->where('course_id', $form->course_id)],
        ], ['batch_id.exists' => 'Choose a batch of this course.']);

        try {
            $registrar->moveTo($registration, Batch::query()->findOrFail($validated['batch_id']), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Moved to {$registration->refresh()->batch?->name}.");
    }

    /* -------------------------------------------------------------------- */

    protected function registrationsTable(RegistrationForm $form): Table
    {
        $questions = $form->questionList();

        return Table::for(Registration::query()
            ->where('registration_form_id', $form->id)
            ->with('batch:id,name', 'user:id,name,role'))
            ->searchable(['name', 'email', 'mobile'])
            ->defaultSort('-created_at')
            ->exportName("registrations-{$form->slug}")
            ->columns([
                Column::make('name', 'Name')->sortable(),
                Column::make('email', 'Email')->sortable(),
                Column::make('mobile', 'Mobile'),
                Column::make('status', 'Status')->sortable(),
                Column::make('batch', 'Batch'),
                Column::make('created_at', 'Registered')->sortable(),
                ...collect($form->standardFields())
                    ->map(fn (string $label, string $key) => Column::make("details_{$key}", $label)->hidden())
                    ->values(),
                ...collect($questions)
                    ->map(fn (array $question) => Column::make("answer_{$question['key']}", Str::limit($question['label'], 40))->hidden())
                    ->values(),
                Column::make('note', 'Note')->hidden(),
            ])
            ->filters([
                Filter::select('status', Registration::STATUSES, 'Status')->placeholder('Any'),
            ])
            ->transform(fn (Registration $registration) => [
                'id' => $registration->id,
                'name' => $registration->name,
                'email' => $registration->email,
                'mobile' => $registration->mobile,
                'status' => $registration->status,
                'statusLabel' => $registration->statusLabel(),
                'batch' => $registration->batch?->name,
                'batchId' => $registration->batch_id,
                'created_at' => $registration->created_at->format('j M Y, g:i a'),
                'newAccount' => $registration->account_created,
                'confirmed' => $registration->account_created || $registration->confirmed_at !== null,
                'studentId' => $registration->user?->isStudent() ? $registration->user_id : null,
                'note' => $registration->note,
                'details' => collect($form->standardFields())
                    ->map(fn (string $label, string $key) => ['label' => $label, 'value' => $registration->details[$key] ?? null])
                    ->values(),
                'answers' => collect($questions)
                    ->map(fn (array $question) => ['label' => $question['label'], 'value' => $this->answerText($question, $registration->answers[$question['key']] ?? null)])
                    ->values(),
                ...collect($form->standardFields())
                    ->mapWithKeys(fn (string $label, string $key) => ["details_{$key}" => $registration->details[$key] ?? null])
                    ->all(),
                ...collect($questions)
                    ->mapWithKeys(fn (array $question) => ["answer_{$question['key']}" => $this->answerText($question, $registration->answers[$question['key']] ?? null)])
                    ->all(),
            ]);
    }

    protected function answerText(array $question, ?string $answer): ?string
    {
        return $question['type'] === 'yesno' && $answer !== null ? ucfirst($answer) : $answer;
    }

    protected function belongs(RegistrationForm $form, Registration $registration): void
    {
        abort_unless($registration->registration_form_id === $form->id, 404);
    }

    protected function qr(RegistrationForm $form, int $size = 320): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))->writeString($form->url());
    }

    /** @return array<string, mixed> */
    protected function formOptions(?RegistrationForm $form): array
    {
        return [
            'form' => $form ? [
                'id' => $form->id,
                'title' => $form->title,
                'slug' => $form->slug,
                'intro' => $form->intro,
                'course_id' => $form->course_id,
                'batch_id' => $form->batch_id,
                'opens_at' => $form->opens_at?->format('Y-m-d\TH:i'),
                'closes_at' => $form->closes_at?->format('Y-m-d\TH:i'),
                'capacity' => $form->capacity,
                'fee' => $form->fee,
                'approval' => $form->approval,
                'fields' => $form->fields ?? [],
                'questions' => $form->questionList(),
                'is_active' => $form->is_active,
                'hasRegistrations' => $form->registrations()->exists(),
            ] : null,
            'courses' => $this->courseOptions(),
            'batches' => Batch::query()
                ->with('course:id,title')
                ->orderByDesc('starts_on')
                ->get(['id', 'name', 'course_id'])
                ->map(fn (Batch $batch) => ['value' => $batch->id, 'label' => $batch->name, 'courseId' => $batch->course_id]),
            'standardFields' => collect(RegistrationForm::STANDARD_FIELDS)
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                ->values(),
            'questionTypes' => collect(RegistrationForm::QUESTION_TYPES)
                ->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])
                ->values(),
            'maxQuestions' => RegistrationForm::MAX_QUESTIONS,
        ];
    }

    protected function courseOptions()
    {
        return Course::query()
            ->where('is_published', true)
            ->orderBy('title')
            ->get(['id', 'title', 'price', 'sale_price', 'type', 'visibility'])
            ->map(fn (Course $course) => [
                'value' => $course->id,
                'label' => $course->title,
                'description' => ($course->isFree() ? 'Free' : 'Paid').($course->visibility !== 'public' ? ' · LMS only' : ''),
            ]);
    }

    protected function fill(Request $request, RegistrationForm $form): void
    {
        $request->merge([
            'slug' => Str::slug((string) ($request->input('slug') ?: $request->input('title'))),
        ]);

        $validated = $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('registration_forms', 'slug')->ignore($form->id)],
            'intro' => ['nullable', 'string', 'max:4000'],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->where('is_published', true)],
            'batch_id' => ['nullable', 'integer'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'fee' => ['required', Rule::in(['course', 'free'])],
            'approval' => ['required', Rule::in(['auto', 'review'])],
            'fields' => ['array'],
            'fields.*' => [Rule::in(array_keys(RegistrationForm::STANDARD_FIELDS))],
            'questions' => ['array', 'max:'.RegistrationForm::MAX_QUESTIONS],
            'questions.*.label' => ['required', 'string', 'max:200'],
            'questions.*.type' => ['required', Rule::in(array_keys(RegistrationForm::QUESTION_TYPES))],
            'questions.*.required' => ['boolean'],
            'questions.*.options' => ['array', 'max:20'],
            'questions.*.options.*' => ['nullable', 'string', 'max:120'],
            'is_active' => ['boolean'],
        ], [
            'slug.unique' => 'Another form already uses that address.',
            'course_id.exists' => 'Choose a published course.',
            'closes_at.after' => 'It has to close after it opens.',
            'questions.max' => 'Keep it to '.RegistrationForm::MAX_QUESTIONS.' questions; a long form is a form people abandon.',
            'questions.*.label.required' => 'Write the question.',
        ]);

        $batchId = $validated['batch_id'];

        if ($batchId && ! Batch::query()->where('course_id', $validated['course_id'])->whereKey($batchId)->exists()) {
            throw ValidationException::withMessages(['batch_id' => 'That batch belongs to another course.']);
        }

        if ($form->exists && $form->course_id !== (int) $validated['course_id'] && $form->registrations()->exists()) {
            throw ValidationException::withMessages(['course_id' => 'People have registered for this course through the form, so it cannot be changed to another. Make a new form instead.']);
        }

        $form->fill([
            'title' => trim($validated['title']),
            'slug' => $validated['slug'],
            'intro' => filled($validated['intro']) ? trim($validated['intro']) : null,
            'course_id' => (int) $validated['course_id'],
            'batch_id' => $batchId ?: null,
            'opens_at' => $validated['opens_at'],
            'closes_at' => $validated['closes_at'],
            'capacity' => $validated['capacity'],
            'fee' => $validated['fee'],
            'approval' => $validated['approval'],
            'fields' => array_values(array_intersect(array_keys(RegistrationForm::STANDARD_FIELDS), $validated['fields'] ?? [])),
            'questions' => $this->questions($request, $form),
            'is_active' => $this->boolInput($request, 'is_active', true),
            'updated_by' => $request->user()->id,
        ]);
    }

    /**
     * The questions as they will be stored, each with a key that stays put.
     *
     * An existing question keeps its key when it is reworded or moved, so the
     * answers already given still line up with it. A new one gets a fresh key.
     *
     * @return array<int, array{key: string, label: string, type: string, required: bool, options: array<int, string>}>
     */
    protected function questions(Request $request, RegistrationForm $form): array
    {
        $known = collect($form->questionList())->pluck('key')->all();
        $errors = [];
        $clean = [];

        foreach (array_values((array) $request->input('questions', [])) as $index => $question) {
            $key = (string) ($question['key'] ?? '');

            if (! in_array($key, $known, true)) {
                $key = 'q'.Str::lower(Str::random(8));
            }

            $options = $question['type'] === 'choice'
                ? array_values(array_unique(array_filter(array_map(fn ($option) => trim((string) $option), (array) ($question['options'] ?? [])), 'strlen')))
                : [];

            if ($question['type'] === 'choice' && count($options) < 2) {
                $errors["questions.{$index}.options"] = 'Give at least two answers to choose from.';
            }

            $clean[] = [
                'key' => $key,
                'label' => trim((string) $question['label']),
                'type' => $question['type'],
                'required' => filter_var($question['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'options' => $options,
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}

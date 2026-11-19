<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizSlot;
use App\Services\Admin\Auditor;
use App\Services\Lms\Proctoring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Proctored quizzes from our side (§9.1a).
 *
 * Setting one up is part of building the quiz (`catalogue.manage`): how it is
 * watched, its sittings, Safe Exam Browser. Watching it is `quiz.proctor`: the
 * console shows every student sitting it, live, and a proctor may send one a
 * message, give more time or end the attempt, each audited with its reason.
 */
class ProctoringController extends Controller
{
    public function __construct(protected Proctoring $proctoring) {}

    /** Sittings open now, today and coming, and every proctored quiz. */
    public function index(Request $request): Response
    {
        $slots = QuizSlot::query()
            ->where('ends_at', '>', now()->subHours(12))
            ->where('starts_at', '<', now()->addDays(30))
            ->with(['quiz:id,title,course_id', 'quiz.course:id,title', 'batch:id,name'])
            ->withCount(['attempts', 'attempts as sitting_count' => fn ($query) => $query->whereNull('submitted_at')])
            ->orderBy('starts_at')
            ->get();

        $quizzes = Quiz::query()
            ->where('proctoring', '!=', 'off')
            ->with('course:id,title')
            ->withCount(['slots as coming_count' => fn ($query) => $query->where('ends_at', '>', now())])
            ->orderBy('title')
            ->get();

        return Inertia::render('admin/proctoring/Index', [
            'slots' => $slots->map(fn (QuizSlot $slot) => [
                'id' => $slot->id,
                'quizId' => $slot->quiz_id,
                'quiz' => $slot->quiz->title,
                'course' => $slot->quiz->course->title,
                'batch' => $slot->batch?->name ?? 'Everybody it is set for',
                'window' => $slot->window(),
                'state' => match (true) {
                    $slot->starts_at->isFuture() => 'coming',
                    $slot->ends_at->isFuture() => 'open',
                    default => 'ended',
                },
                'started' => $slot->attempts_count,
                'sitting' => $slot->sitting_count,
                'note' => $slot->note,
            ]),
            'quizzes' => $quizzes->map(fn (Quiz $quiz) => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'course' => $quiz->course->title,
                'requireSeb' => $quiz->require_seb,
                'maxExits' => $quiz->max_exits,
                'comingSittings' => $quiz->coming_count,
            ]),
            'canManage' => $request->user()->hasPermission('catalogue.manage'),
        ]);
    }

    /* ------------------------------------------------- setting one up */

    public function settings(Request $request, Quiz $quiz): Response
    {
        $quiz->loadMissing('course:id,title');

        return Inertia::render('admin/proctoring/Quiz', [
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'courseId' => $quiz->course_id,
                'course' => $quiz->course->title,
                'timeLimit' => $quiz->time_limit_minutes,
                'isPublished' => $quiz->is_published,
                'proctoring' => $quiz->proctoring,
                'maxExits' => $quiz->max_exits,
                'requireSeb' => $quiz->require_seb,
                'sebConfigKeys' => implode("\n", $quiz->seb_config_keys ?? []),
                'sebExamKeys' => implode("\n", $quiz->seb_exam_keys ?? []),
                'sebFile' => $quiz->seb_config_path !== null,
            ],
            'slots' => $quiz->slots()->with('batch:id,name')->withCount('attempts')->get()->map(fn (QuizSlot $slot) => [
                'id' => $slot->id,
                'window' => $slot->window(),
                'batch' => $slot->batch?->name ?? 'Everybody it is set for',
                'note' => $slot->note,
                'started' => $slot->attempts_count,
                'state' => match (true) {
                    $slot->starts_at->isFuture() => 'coming',
                    $slot->ends_at->isFuture() => 'open',
                    default => 'ended',
                },
            ]),
            'batches' => Batch::query()
                ->where('course_id', $quiz->course_id)
                ->when($quiz->batch_id, fn ($query) => $query->whereKey($quiz->batch_id))
                ->orderBy('starts_on')
                ->get(['id', 'name'])
                ->map(fn (Batch $batch) => ['value' => $batch->id, 'label' => $batch->name]),
            'canProctor' => $request->user()->hasPermission('quiz.proctor'),
        ]);
    }

    public function updateSettings(Request $request, Quiz $quiz, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            // The camera waits for a video relay (X12).
            'proctoring' => ['required', Rule::in(['off', 'lockdown'])],
            'max_exits' => ['nullable', 'integer', 'min:1', 'max:20'],
            'require_seb' => ['boolean'],
            'seb_config_keys' => ['nullable', 'string', 'max:5000'],
            'seb_exam_keys' => ['nullable', 'string', 'max:5000'],
        ], [
            'proctoring.in' => 'The camera joins once a video relay is set up. Choose lockdown for now.',
        ]);

        $configKeys = $this->keys($validated['seb_config_keys']);
        $examKeys = $this->keys($validated['seb_exam_keys']);

        if ($configKeys === null || $examKeys === null) {
            return back()->withErrors([($configKeys === null ? 'seb_config_keys' : 'seb_exam_keys') => 'Each key is 64 characters of 0–9 and a–f, one to a line, as Safe Exam Browser shows it.']);
        }

        if ($validated['require_seb'] && $validated['proctoring'] === 'off') {
            return back()->withErrors(['require_seb' => 'Safe Exam Browser goes with a proctored quiz. Choose lockdown as well.']);
        }

        if ($validated['require_seb'] && $configKeys === [] && $examKeys === []) {
            return back()->withErrors(['seb_config_keys' => 'Add the configuration key from Safe Exam Browser\'s settings, or nobody could start it.']);
        }

        $quiz->fill([
            'proctoring' => $validated['proctoring'],
            'max_exits' => $validated['proctoring'] === 'off' ? null : $validated['max_exits'],
            'require_seb' => $validated['require_seb'],
            'seb_config_keys' => $configKeys ?: null,
            'seb_exam_keys' => $examKeys ?: null,
        ]);

        $auditor->updated($quiz, label: $quiz->title.' — proctoring');
        $quiz->save();

        return back()->with('success', 'Proctoring saved.');
    }

    /** The settings file students' computers open the quiz in Safe Exam Browser with. */
    public function uploadSebFile(Request $request, Quiz $quiz, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'file' => ['required', 'file', 'max:2048'],
        ], [
            'file.max' => 'A Safe Exam Browser settings file is a few kilobytes; that one is over 2 MB.',
        ]);

        if (strtolower($validated['file']->getClientOriginalExtension()) !== 'seb') {
            return back()->withErrors(['file' => 'Choose the .seb file Safe Exam Browser\'s configuration tool saved.']);
        }

        $old = $quiz->seb_config_path;
        $path = $validated['file']->storeAs('quizzes/safe-exam-browser', $quiz->id.'-'.Str::lower(Str::random(10)).'.seb', 'local');

        $quiz->forceFill(['seb_config_path' => $path])->save();

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        $auditor->action('quiz.seb_file', $quiz, [], $quiz->title);

        return back()->with('success', 'Settings file saved. Students get a button that opens the quiz with it.');
    }

    public function storeSlot(Request $request, Quiz $quiz, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'batch_id' => ['nullable', 'integer', Rule::exists('batches', 'id')->where('course_id', $quiz->course_id)],
            'note' => ['nullable', 'string', 'max:160'],
        ], [
            'ends_at.after' => 'A sitting ends after it starts.',
        ]);

        $starts = Carbon::parse($validated['starts_at']);
        $ends = Carbon::parse($validated['ends_at']);

        $problem = match (true) {
            $ends->isPast() => ['ends_at', 'That sitting would already be over.'],
            $starts->diffInMinutes($ends) > 12 * 60 => ['ends_at', 'A sitting is at most twelve hours. Add another for another day.'],
            $quiz->time_limit_minutes && $starts->diffInMinutes($ends) < $quiz->time_limit_minutes => ['ends_at', "Shorter than the quiz's {$quiz->time_limit_minutes} minutes, so it would cut everybody short."],
            $quiz->batch_id && $validated['batch_id'] && (int) $validated['batch_id'] !== $quiz->batch_id => ['batch_id', 'This quiz is set for one batch only.'],
            default => null,
        };

        if ($problem) {
            return back()->withErrors([$problem[0] => $problem[1]]);
        }

        $slot = $quiz->slots()->create([
            'batch_id' => $validated['batch_id'],
            'starts_at' => $starts,
            'ends_at' => $ends,
            'note' => $validated['note'],
            'created_by' => $request->user()->id,
        ]);

        $auditor->created($slot, $quiz->title.' — '.$slot->window());

        return back()->with('success', 'Sitting added.');
    }

    public function destroySlot(QuizSlot $slot, Auditor $auditor): RedirectResponse
    {
        if ($slot->attempts()->exists()) {
            return back()->withErrors(['slot' => 'Students have started in this sitting, so it stays on the record.']);
        }

        $auditor->deleted($slot, $slot->quiz->title.' — '.$slot->window());
        $slot->delete();

        return back()->with('success', 'Sitting removed.');
    }

    /* ----------------------------------------------------- watching one */

    public function console(Quiz $quiz): Response
    {
        $quiz->loadMissing('course:id,title');
        $slot = $this->currentSlot($quiz);

        return Inertia::render('admin/proctoring/Console', [
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'course' => $quiz->course->title,
                'maxExits' => $quiz->max_exits,
                'requireSeb' => $quiz->require_seb,
                'proctored' => $quiz->isProctored(),
            ],
            'sitting' => $slot ? ['window' => $slot->window(), 'open' => $slot->isOpen(), 'note' => $slot->note] : null,
            'maxExtraMinutes' => Proctoring::MAX_EXTRA_MINUTES,
            ...$this->watch($quiz, $slot),
        ]);
    }

    /** The console's poll: the same as the page, for when the socket is down. */
    public function status(Quiz $quiz): JsonResponse
    {
        return response()->json($this->watch($quiz, $this->currentSlot($quiz)));
    }

    public function message(Request $request, QuizAttempt $attempt): RedirectResponse
    {
        $validated = $this->validatedInput($request, ['text' => ['required', 'string', 'max:300']]);

        return $this->act($attempt, fn () => $this->proctoring->message($attempt, $request->user(), trim($validated['text'])), 'Sent. It shows over their quiz within a few seconds.');
    }

    public function addTime(Request $request, QuizAttempt $attempt): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'minutes' => ['required', 'integer', 'min:1', 'max:'.Proctoring::MAX_EXTRA_MINUTES],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        return $this->act($attempt, fn () => $this->proctoring->addTime($attempt, $request->user(), (int) $validated['minutes'], trim($validated['reason'])), "{$validated['minutes']} ".((int) $validated['minutes'] === 1 ? 'minute' : 'minutes').' added.');
    }

    public function end(Request $request, QuizAttempt $attempt): RedirectResponse
    {
        $validated = $this->validatedInput($request, ['reason' => ['required', 'string', 'max:500']]);

        return $this->act($attempt, fn () => $this->proctoring->end($attempt, $request->user(), trim($validated['reason'])), 'Attempt ended. They have been told why.');
    }

    /* --------------------------------------------------------- helpers */

    protected function act(QuizAttempt $attempt, callable $action, string $done): RedirectResponse
    {
        abort_unless($attempt->isProctored(), 404);

        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->withErrors(['attempt' => $e->getMessage()]);
        }

        return back()->with('success', $done);
    }

    /**
     * Everybody sitting it now, or who finished in the last few hours, and who
     * the open sitting is waiting for.
     *
     * @return array<string, mixed>
     */
    protected function watch(Quiz $quiz, ?QuizSlot $slot): array
    {
        $attempts = $quiz->attempts()
            ->whereNotNull('proctoring')
            ->where(fn ($query) => $query->whereNull('submitted_at')->orWhere('submitted_at', '>', now()->subHours(3)))
            ->with('user:id,name')
            ->get();

        return [
            'tiles' => $attempts->map(fn (QuizAttempt $attempt) => $this->proctoring->tile($attempt))->values()->all(),
            'waiting' => $this->waiting($quiz, $slot, $attempts->pluck('user_id')->all()),
            'now' => now()->toIso8601String(),
            'quietSeconds' => Proctoring::QUIET_SECONDS,
        ];
    }

    /** The sitting open now, or the nearest one starting soon or just ended. */
    protected function currentSlot(Quiz $quiz): ?QuizSlot
    {
        return $quiz->slots()->where('starts_at', '<=', now())->where('ends_at', '>', now())->reorder('ends_at', 'desc')->first()
            ?? $quiz->slots()->where('starts_at', '>', now())->where('starts_at', '<', now()->addHours(2))->first()
            ?? $quiz->slots()->where('ends_at', '<=', now())->where('ends_at', '>', now()->subHours(2))->reorder('ends_at', 'desc')->first();
    }

    /**
     * Who the sitting is for and has not started yet, by name: a proctor
     * expecting twenty and seeing twelve should know which eight.
     *
     * @param  array<int, int>  $started
     * @return array<int, string>
     */
    protected function waiting(Quiz $quiz, ?QuizSlot $slot, array $started): array
    {
        if (! $slot || $slot->ends_at->isPast()) {
            return [];
        }

        $batchId = $slot->batch_id ?? $quiz->batch_id;

        return Enrollment::query()
            ->active()
            ->where('course_id', $quiz->course_id)
            ->when($batchId, fn ($query) => $query->where('batch_id', $batchId))
            ->whereNotIn('user_id', $started)
            ->with('user:id,name')
            ->get()
            ->map(fn (Enrollment $enrolment) => $enrolment->user?->name)
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Keys as Safe Exam Browser shows them, one to a line: an empty list for
     * none, null when one of them is not a key.
     *
     * @return array<int, string>|null
     */
    protected function keys(?string $text): ?array
    {
        $keys = collect(preg_split('/[\s,;]+/', strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY))->unique()->values();

        return $keys->every(fn (string $key) => preg_match('/^[0-9a-f]{64}$/', $key) === 1) ? $keys->all() : null;
    }
}

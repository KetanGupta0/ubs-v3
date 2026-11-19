<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAiReview;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptEvent;
use App\Models\QuizIntegrityDecision;
use App\Services\Lms\AiReview;
use App\Services\Lms\IntegrityChecks;
use App\Services\Lms\IntegrityDecisions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * A trainer's look at what the integrity checks found (§9.1, layer 4).
 *
 * The flagged attempts of a quiz, the most worrying first; then one attempt
 * in full: its timeline, its answers beside the ones the checks matched, the
 * AI's reading where there is one, and the decision. Nothing on these screens
 * changes a score by itself; a decision does, and it is audited.
 */
class QuizIntegrityController extends Controller
{
    /** Higher worry first; an attempt not yet read sits between medium and low. */
    protected const RISK_ORDER = ['high' => 0, 'medium' => 1, 'unread' => 2, 'low' => 3, 'none' => 4];

    public function __construct(
        protected AiReview $ai,
        protected IntegrityDecisions $decisions,
    ) {}

    public function index(Course $course, Quiz $quiz): Response
    {
        abort_unless($quiz->course_id === $course->id, 404);

        $attempts = $quiz->attempts()
            ->whereNotNull('submitted_at')
            ->where(fn ($query) => $query->whereNotNull('flagged_at')->orWhereHas('aiReviews')->orWhereHas('decision'))
            ->with(['user:id,name', 'latestAiReview', 'decision'])
            ->get()
            ->sortBy(fn (QuizAttempt $attempt) => [
                $attempt->decision?->isFinal() ? 1 : 0,
                self::RISK_ORDER[$attempt->latestAiReview?->status === 'done' ? $attempt->latestAiReview->risk : 'unread'] ?? 2,
                -count($attempt->flags ?? []),
                $attempt->user?->name,
            ])
            ->values();

        return Inertia::render('admin/assessment/Integrity', [
            'course' => ['id' => $course->id, 'title' => $course->title],
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'checkedAt' => $quiz->integrity_checked_at?->format('j M, g:i a'),
                'closesAt' => $quiz->closes_at?->format('j M, g:i a'),
                'submitted' => $quiz->attempts()->whereNotNull('submitted_at')->count(),
            ],
            'aiAvailable' => $this->ai->available(),
            'attempts' => $attempts->map(fn (QuizAttempt $attempt) => [
                'id' => $attempt->id,
                'student' => $attempt->user?->name ?? 'A student',
                'number' => $attempt->attempt_number,
                'percent' => (float) $attempt->percent,
                'submittedAt' => $attempt->submitted_at->format('j M, g:i a'),
                'voided' => $attempt->isVoided(),
                'flags' => collect($attempt->flags ?? [])->map(fn (array $flag) => ['type' => $flag['type'], 'summary' => $flag['summary']])->all(),
                'ai' => $this->aiSummary($attempt->latestAiReview),
                'decision' => $attempt->decision ? [
                    'label' => $attempt->decision->label(),
                    'outcome' => $attempt->decision->outcome,
                    'final' => $attempt->decision->isFinal(),
                    'replied' => $attempt->decision->reply !== null,
                ] : null,
            ]),
        ]);
    }

    /** Run the checks now, rather than when the scheduler next gets to them. */
    public function check(Course $course, Quiz $quiz, IntegrityChecks $checks): RedirectResponse
    {
        abort_unless($quiz->course_id === $course->id, 404);

        $flagged = $checks->run($quiz);
        $queued = $this->ai->queueFlagged($quiz);

        return back()->with('success', "Checked. {$flagged} ".($flagged === 1 ? 'attempt' : 'attempts').' to look at.'
            .($queued ? " {$queued} sent to the AI with the next batch." : ''));
    }

    public function show(QuizAttempt $attempt): Response
    {
        abort_if($attempt->isOpen(), 404);

        $attempt->load(['quiz.course:id,title', 'user:id,name', 'answers', 'events', 'aiReviews', 'decision.decider:id,name']);
        $quiz = $attempt->quiz;
        $paper = $attempt->paper()->values();
        $number = $paper->mapWithKeys(fn (Question $question, int $index) => [$question->id => $index + 1]);
        $answers = $attempt->answers->keyBy('question_id');
        $clock = fn ($time) => $time && $attempt->started_at ? $this->clock((int) round($attempt->started_at->diffInSeconds($time, false))) : null;

        // Whoever the flags compare this attempt with, and their answers.
        $others = QuizAttempt::query()
            ->whereKey(collect($attempt->flags ?? [])->pluck('evidence.with')->filter()->unique())
            ->with(['user:id,name', 'answers'])
            ->get()
            ->keyBy('id');

        return Inertia::render('admin/assessment/IntegrityAttempt', [
            'quiz' => ['id' => $quiz->id, 'title' => $quiz->title, 'courseId' => $quiz->course_id, 'course' => $quiz->course->title],
            'attempt' => [
                'id' => $attempt->id,
                'student' => $attempt->user?->name ?? 'A student',
                'number' => $attempt->attempt_number,
                'score' => $attempt->score,
                'totalMarks' => $attempt->total_marks,
                'percent' => (float) $attempt->percent,
                'startedAt' => $attempt->started_at?->format('j M, g:i a'),
                'took' => $clock($attempt->submitted_at),
                'voided' => $attempt->isVoided(),
                'proctored' => $attempt->isProctored(),
                'endedReason' => $attempt->ended_reason,
            ],
            'flags' => collect($attempt->flags ?? [])->map(fn (array $flag) => [
                'type' => $flag['type'],
                'summary' => $flag['summary'],
                'with' => isset($flag['evidence']['with'], $others[$flag['evidence']['with']])
                    ? ['id' => (int) $flag['evidence']['with'], 'student' => $others[$flag['evidence']['with']]->user?->name ?? 'A student']
                    : null,
                'questions' => collect($flag['evidence']['questions'] ?? [])->map(fn ($id) => $number[(int) $id] ?? null)->filter()->values()->all(),
            ])->all(),
            'timeline' => $attempt->events->map(fn (QuizAttemptEvent $event) => [
                'id' => $event->id,
                'at' => $clock($event->occurred_at),
                'label' => $event->label(),
                'question' => $event->question_id ? ($number[$event->question_id] ?? null) : null,
                // Extra time says its minutes in the detail.
                'seconds' => $event->type === 'time_added' ? null : $event->duration_seconds,
                'detail' => $this->detail($event),
                'fromBrowser' => in_array($event->type, QuizAttemptEvent::FROM_BROWSER, true),
            ])->values(),
            'answers' => $paper->map(function (Question $question) use ($answers, $number, $clock, $others) {
                /** @var QuizAnswer|null $answer */
                $answer = $answers->get($question->id);

                return [
                    'number' => $number[$question->id],
                    'question' => $question->body,
                    'type' => $question->typeLabel(),
                    'autoMarked' => $question->isAutoMarked(),
                    'correct' => $question->isAutoMarked() ? $question->correct : null,
                    'answer' => $answer?->response,
                    'right' => $answer?->is_correct,
                    'seconds' => $answer?->seconds_spent,
                    'answeredAt' => $clock($answer?->answered_at),
                    'others' => $others->map(fn (QuizAttempt $other) => [
                        'student' => $other->user?->name ?? 'A student',
                        'answer' => $other->answers->firstWhere('question_id', $question->id)?->response,
                    ])->filter(fn (array $other) => $other['answer'] !== null)->values(),
                ];
            }),
            'reviews' => $attempt->aiReviews->sortByDesc('id')->values()->map(fn (QuizAiReview $review) => [
                ...$this->aiSummary($review),
                'evidence' => $review->evidence ?? [],
                'explanations' => $review->explanations ?? [],
                'model' => $review->model,
                'at' => ($review->finished_at ?? $review->sent_at ?? $review->created_at)?->format('j M, g:i a'),
            ]),
            'decision' => $attempt->decision ? [
                'outcome' => $attempt->decision->outcome,
                'label' => $attempt->decision->label(),
                'reason' => $attempt->decision->reason,
                'note' => $attempt->decision->note,
                'resit' => $attempt->decision->resit,
                'by' => $attempt->decision->decider?->name,
                'at' => $attempt->decision->decided_at->format('j M, g:i a'),
                'told' => $attempt->decision->told_at !== null,
                'reply' => $attempt->decision->reply,
                'repliedAt' => $attempt->decision->replied_at?->format('j M, g:i a'),
                'final' => $attempt->decision->isFinal(),
            ] : null,
            'outcomes' => collect(QuizIntegrityDecision::OUTCOMES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'aiAvailable' => $this->ai->available(),
            'aiPending' => $attempt->aiReviews->contains(fn (QuizAiReview $review) => $review->isPending()),
        ]);
    }

    public function askAi(Request $request, QuizAttempt $attempt): RedirectResponse
    {
        if (! $this->ai->available()) {
            return back()->withErrors(['ai' => 'The Claude API key is not set, so there is no AI review.']);
        }

        return $this->ai->queue($attempt, $request->user())
            ? back()->with('success', 'Asked. The answer comes back with the next batch, usually within the hour.')
            : back()->with('success', 'Already waiting for an answer.');
    }

    public function decide(Request $request, QuizAttempt $attempt): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'outcome' => ['required', Rule::in(array_keys(QuizIntegrityDecision::OUTCOMES))],
            'reason' => [Rule::requiredIf(fn () => $request->input('outcome') !== 'cleared'), 'nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'resit' => ['boolean'],
        ], [
            'reason.required' => 'Say why, in words the student will read.',
        ]);

        try {
            $decision = $this->decisions->decide($attempt, $request->user(), $validated['outcome'], $validated['reason'], $validated['note'], $validated['resit']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['outcome' => $e->getMessage()]);
        }

        return back()->with('success', match (true) {
            $decision->isFinal() => 'Cleared. Nothing is sent: they were never told anything was looked at.',
            $decision->outcome === 'warned' => 'Decided, and the warning is on their record. They can reply once before it is final.',
            default => 'Decided, and they have been told. They can reply once before it is final.',
        });
    }

    public function finalise(Request $request, QuizAttempt $attempt): RedirectResponse
    {
        $decision = $attempt->decision;
        abort_unless($decision !== null, 404);

        $this->decisions->finalise($decision, $request->user());

        return back()->with('success', 'Final.');
    }

    /** @return array<string, mixed>|null */
    protected function aiSummary(?QuizAiReview $review): ?array
    {
        return $review ? [
            'status' => $review->status,
            'risk' => $review->risk,
            'summary' => $review->summary,
            'error' => $review->error,
        ] : null;
    }

    /** What an event adds, for a person to read; never an address. */
    protected function detail(QuizAttemptEvent $event): ?string
    {
        $detail = $event->detail ?? [];

        return match ($event->type) {
            'paste', 'copy' => isset($detail['length']) ? number_format((int) $detail['length']).' characters' : null,
            'blocked' => trim(($detail['what'] ?? '').(isset($detail['key']) ? ' ('.$detail['key'].')' : '')) ?: null,
            'focus_lost' => match ($detail['why'] ?? null) {
                'fullscreen' => 'left full screen',
                'tab' => 'switched tab',
                'window' => 'switched window',
                default => null,
            },
            'proctor_message' => $detail['text'] ?? null,
            'time_added' => isset($detail['minutes']) ? "{$detail['minutes']} minutes: ".($detail['reason'] ?? '') : null,
            'ended_by_proctor' => $detail['reason'] ?? null,
            default => null,
        };
    }

    protected function clock(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}

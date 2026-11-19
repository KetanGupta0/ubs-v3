<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LeaderboardPoint;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAiReview;
use App\Models\QuizAttempt;
use App\Models\StudentWarning;
use App\Models\User;
use App\Notifications\IntegrityDecisionMade;
use App\Notifications\IntegrityFlagsFound;
use App\Notifications\IntegrityReplyReceived;
use App\Services\Ai\ClaudeBatches;
use App\Services\Lms\AiReview;
use App\Services\Lms\IntegrityChecks;
use App\Services\Lms\IntegrityDecisions;
use App\Services\Lms\QuizGrader;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

/*
 * Layers 3 and 4 of quiz integrity (§9.1): an AI reads a flagged attempt with
 * nobody named, and a person decides, which the student is told and can
 * answer once. The Message Batches API is stood in for: nothing here calls it.
 */

/** Stands in for the API: records what would be sent and answers as told. */
class FakeReviewBatches extends ClaudeBatches
{
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $sent = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    public array $answers = [];

    public bool $finished = true;

    public function configured(): bool
    {
        return true;
    }

    public function create(array $requests): string
    {
        $this->sent[] = $requests;

        return 'msgbatch_'.count($this->sent);
    }

    public function ended(string $batchId): bool
    {
        return $this->finished;
    }

    public function results(string $batchId): Generator
    {
        foreach ($this->answers[$batchId] ?? [] as $customId => $outcome) {
            yield $customId => $outcome + ['type' => 'succeeded', 'text' => null, 'stop' => 'end_turn', 'input_tokens' => 3000, 'output_tokens' => 400, 'error' => null];
        }
    }
}

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->trainer = User::factory()->admin()->create(['name' => 'Asha Trainer']);
    $this->trainer->permissions()->attach(Permission::query()->where('key', 'students.view')->sole());

    $this->meera = User::factory()->student()->create(['name' => 'Meera Iyer', 'email' => 'meera.iyer@example.com']);
    $this->zoya = User::factory()->student()->create(['name' => 'Zoya Khan']);

    $this->course = Course::query()->create(['title' => 'Databases', 'slug' => 'review-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'y']);
    $this->batch = Batch::query()->create(['course_id' => $this->course->id, 'name' => 'December batch', 'code' => 'RV-'.uniqid(), 'starts_on' => today()->subWeek(), 'trainer_id' => $this->trainer->id]);

    foreach ([$this->meera, $this->zoya] as $student) {
        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id, 'enrolled_at' => now()->subWeek(), 'has_paid' => true]);
    }

    $this->quiz = Quiz::query()->create([
        'course_id' => $this->course->id, 'title' => 'Keys and joins', 'time_limit_minutes' => 20,
        'attempts_allowed' => 1, 'pass_percent' => 50, 'shuffle_questions' => false, 'is_published' => true,
    ]);
    $this->choice = Question::query()->create(['quiz_id' => $this->quiz->id, 'type' => 'mcq', 'body' => 'What does a primary key guarantee?', 'options' => ['Speed', 'Uniqueness'], 'correct' => ['Uniqueness'], 'marks' => 1, 'sort_order' => 0]);
    $this->written = Question::query()->create(['quiz_id' => $this->quiz->id, 'type' => 'short', 'body' => 'Why does a foreign key exist?', 'marks' => 4, 'sort_order' => 1]);

    // Two attempts with near identical written answers, one of which signs itself.
    $this->shared = 'A foreign key makes sure a row in one table can only point at a record that really exists in the other table, so the database itself refuses orders for customers who were never created, keeps every reference honest when rows are deleted, and lets the database do the checking instead of every page doing it again';
    $grader = app(QuizGrader::class);
    $this->mine = $grader->start($this->quiz, $this->meera, $this->batch->id);
    $this->theirs = $grader->start($this->quiz, $this->zoya, $this->batch->id);
    $grader->submit($this->mine, [$this->choice->id => 'Uniqueness', $this->written->id => $this->shared.'. Meera Iyer, meera.iyer@example.com, 98765 43210']);
    $grader->submit($this->theirs, [$this->choice->id => 'Uniqueness', $this->written->id => $this->shared.'.']);
    app(IntegrityChecks::class)->run($this->quiz);

    $this->fake = new FakeReviewBatches;
    app()->instance(ClaudeBatches::class, $this->fake);
});

/* ------------------------------------------------------- the AI's reading */

it('sends a flagged attempt with nobody named, as one batch, to the model and in the shape set', function () {
    $ai = app(AiReview::class);

    expect($this->mine->fresh()->flags)->not->toBeNull()
        ->and($ai->queueFlagged($this->quiz))->toBe(2)
        ->and($ai->queueFlagged($this->quiz))->toBe(0)
        ->and($ai->send())->toBe(2);

    expect($this->fake->sent)->toHaveCount(1);
    $request = collect($this->fake->sent[0])->firstWhere('customID', 'review-'.$this->mine->aiReviews()->value('id'));

    expect($request['params'])
        ->model->toBe('claude-opus-5-5')
        ->system->toBe(AiReview::SYSTEM)
        ->and($request['params']['outputConfig'])->toBe(['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => AiReview::SCHEMA]]);

    $content = $request['params']['messages'][0]['content'];

    expect($content)
        ->toContain('"student": "Student A"')
        ->toContain('Student B')
        ->toContain('again. [name] [name], [email], [number]')
        ->not->toContain('Meera')
        ->not->toContain('Iyer')
        ->not->toContain('Zoya')
        ->not->toContain('example.com')
        ->not->toContain('127.0.0.1')
        ->and(AiReview::SYSTEM)->toContain('Never say or suggest that an answer was written by an AI');

    expect($this->mine->aiReviews()->sole())->status->toBe('sent')->batch_id->toBe('msgbatch_1')->model->toBe('claude-opus-5-5');
});

it('reads back what came, and says what did not', function () {
    $ai = app(AiReview::class);
    $ai->queueFlagged($this->quiz);
    $ai->send();
    [$first, $second] = [$this->mine->aiReviews()->sole(), $this->theirs->aiReviews()->sole()];

    // Not finished yet: nothing moves.
    $this->fake->finished = false;
    expect($ai->collect())->toBe(0);
    $this->fake->finished = true;

    $this->fake->answers['msgbatch_1'] = [
        'review-'.$first->id => ['text' => json_encode([
            'risk' => 'medium',
            'summary' => 'Question 2 matches Student B almost word for word, submitted minutes apart.',
            'evidence' => [['points_at' => 'Question 2', 'what' => 'Same sentence as Student B, with the same unusual phrasing.']],
            'innocent_explanations' => ['They may have revised from the same notes.'],
        ])],
        'review-'.$second->id => ['text' => '', 'stop' => 'refusal'],
    ];

    expect($ai->collect())->toBe(2);

    expect($first->fresh())
        ->status->toBe('done')
        ->risk->toBe('medium')
        ->evidence->toBe([['points_at' => 'Question 2', 'what' => 'Same sentence as Student B, with the same unusual phrasing.']])
        ->explanations->toBe(['They may have revised from the same notes.'])
        ->input_tokens->toBe(3000)
        ->and($second->fresh())->status->toBe('failed')->error->toBe('The model declined to review this attempt.');

    // An answer that cannot be read, an error, and a batch that expired.
    $third = app(AiReview::class)->queue($this->mine);
    $fourth = QuizAiReview::query()->create(['quiz_attempt_id' => $this->theirs->id, 'status' => 'queued']);
    $fifth = QuizAiReview::query()->create(['quiz_attempt_id' => $this->theirs->id, 'status' => 'queued']);
    $ai->send();
    $this->fake->answers['msgbatch_2'] = [
        'review-'.$third->id => ['text' => '{"risk": "certain"}'],
        'review-'.$fourth->id => ['type' => 'errored', 'error' => 'Overloaded'],
        'review-'.$fifth->id => ['type' => 'expired'],
    ];
    $ai->collect();

    expect($third->fresh())->status->toBe('failed')->error->toBe('The answer could not be read.')
        ->and($fourth->fresh())->status->toBe('failed')->error->toBe('Overloaded')
        ->and($fifth->fresh())->status->toBe('queued')->batch_id->toBeNull();
});

it('does nothing at all without the key', function () {
    app()->forgetInstance(ClaudeBatches::class);
    app()->instance(ClaudeBatches::class, new ClaudeBatches);
    config()->set('services.anthropic.key', null);

    expect(app(AiReview::class)->queueFlagged($this->quiz))->toBe(0)
        ->and(QuizAiReview::query()->count())->toBe(0);

    $this->artisan('quizzes:ai-review')->expectsOutputToContain('The Claude API key is not set')->assertSuccessful();

    $this->actingAs($this->trainer)->post("/admin/quiz-attempts/{$this->mine->id}/ai-review")
        ->assertSessionHasErrors(['ai' => 'The Claude API key is not set, so there is no AI review.']);
});

it('queues what the scheduled check flags, and tells the batch trainer there is something to look at', function () {
    Notification::fake();
    QuizAttempt::query()->update(['flags' => null, 'flagged_at' => null]);
    $this->quiz->update(['closes_at' => now()->subHour(), 'integrity_checked_at' => null]);

    $this->artisan('quizzes:check-integrity')->assertSuccessful();

    expect(QuizAiReview::query()->where('status', 'queued')->count())->toBe(2);

    Notification::assertSentTo($this->trainer, IntegrityFlagsFound::class, fn (IntegrityFlagsFound $notice) => $notice->toArray($this->trainer)['url'] === "/admin/courses/{$this->course->id}/quizzes/{$this->quiz->id}/integrity");
    Notification::assertNotSentTo($this->meera, IntegrityFlagsFound::class);

    $this->artisan('quizzes:ai-review')->expectsOutputToContain('Sent 2 for review')->assertSuccessful();
});

/* ---------------------------------------------------------- the decision */

it('clears a first look finally and tells nobody, because nobody was told anything', function () {
    Notification::fake();

    $decision = app(IntegrityDecisions::class)->decide($this->mine, $this->trainer, 'cleared', null, 'Same tutor, same notes.');

    expect($decision)->final_at->not->toBeNull()->told_at->toBeNull();
    Notification::assertNothingSent();

    $this->actingAs($this->meera)
        ->get("/student/quizzes/{$this->quiz->id}/attempts/{$this->mine->id}/review")
        ->assertInertia(fn ($page) => $page->where('integrity', null));
});

it('tells the student what was decided, takes one reply, and makes it final', function () {
    Notification::fake();

    $this->actingAs($this->trainer)
        ->post("/admin/quiz-attempts/{$this->mine->id}/decision", ['outcome' => 'discuss'])
        ->assertSessionHasErrors(['reason' => 'Say why, in words the student will read.']);

    $this->actingAs($this->trainer)
        ->post("/admin/quiz-attempts/{$this->mine->id}/decision", [
            'outcome' => 'discuss',
            'reason' => 'Your answer to question 2 is almost word for word the same as another in your batch.',
            'note' => 'Probably notes from the same senior.',
        ])
        ->assertSessionHas('success');

    Notification::assertSentTo($this->meera, IntegrityDecisionMade::class, fn (IntegrityDecisionMade $notice) => str_contains($notice->toArray($this->meera)['title'], 'would like to talk'));

    $review = "/student/quizzes/{$this->quiz->id}/attempts/{$this->mine->id}/review";
    $this->actingAs($this->meera)->get($review)->assertInertia(fn ($page) => $page
        ->where('integrity.outcome', 'discuss')
        ->where('integrity.reason', 'Your answer to question 2 is almost word for word the same as another in your batch.')
        ->where('integrity.awaitsReply', true)
        ->missing('integrity.note'));

    $this->actingAs($this->meera)->post("/student/quizzes/{$this->quiz->id}/attempts/{$this->mine->id}/integrity-reply", ['reply' => 'We revised together from the class notes the night before.'])
        ->assertSessionHas('success');
    Notification::assertSentTo($this->trainer, IntegrityReplyReceived::class);

    $this->actingAs($this->meera)->post("/student/quizzes/{$this->quiz->id}/attempts/{$this->mine->id}/integrity-reply", ['reply' => 'And another thing'])
        ->assertSessionHasErrors('reply');

    // Somebody else's attempt has nothing for them to reply to.
    $this->actingAs($this->zoya)->post("/student/quizzes/{$this->quiz->id}/attempts/{$this->mine->id}/integrity-reply", ['reply' => 'x'])->assertNotFound();

    $this->actingAs($this->trainer)->post("/admin/quiz-attempts/{$this->mine->id}/decision/final")->assertSessionHas('success', 'Final.');
    Notification::assertSentTo($this->meera, IntegrityDecisionMade::class, fn (IntegrityDecisionMade $notice) => $notice->final);

    $this->actingAs($this->trainer)
        ->post("/admin/quiz-attempts/{$this->mine->id}/decision", ['outcome' => 'cleared'])
        ->assertSessionHasErrors(['outcome' => 'That decision is final.']);

    expect(AuditLog::query()->where('action', 'quiz.integrity_decided')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'quiz.integrity_replied')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'quiz.integrity_final')->count())->toBe(1);
});

it('voids an attempt out of everything that counts, gives the resit, and gives it all back if the decision changes', function () {
    Notification::fake();
    $decisions = app(IntegrityDecisions::class);
    $points = fn () => LeaderboardPoint::query()->where('user_id', $this->meera->id)->where('source', 'quiz.passed')->count();

    // A pass: the written answer marked, so the attempt counts and earns its points.
    app(QuizGrader::class)->mark($this->mine->answers()->where('question_id', $this->written->id)->sole(), 4);
    expect($this->quiz->bestAttemptFor($this->meera)?->id)->toBe($this->mine->id)->and($points())->toBe(1)
        ->and($this->quiz->canBeAttemptedBy($this->meera))->toBeFalse();

    $decisions->decide($this->mine->fresh(), $this->trainer, 'voided', 'The written answer is not your own work.', null, resit: true);

    expect($this->mine->fresh()->isVoided())->toBeTrue()
        ->and($this->quiz->bestAttemptFor($this->meera))->toBeNull()
        ->and($points())->toBe(0)
        ->and($this->quiz->canBeAttemptedBy($this->meera))->toBeTrue();

    $this->actingAs($this->meera)->get("/student/quizzes/{$this->quiz->id}")
        ->assertInertia(fn ($page) => $page->where('attempts.0.voided', true));

    // Thought better of: the attempt counts again, and so do its points.
    $decisions->decide($this->mine->fresh(), $this->trainer, 'cleared', 'Having heard from you, it stands.', null);

    expect($this->mine->fresh()->isVoided())->toBeFalse()
        ->and($points())->toBe(1)
        ->and($this->mine->decision->told_at)->not->toBeNull();
    Notification::assertSentTo($this->meera, IntegrityDecisionMade::class, fn (IntegrityDecisionMade $notice) => $notice->decision->outcome === 'cleared');
});

it('puts a warning on the usual ladder, which tells the student itself', function () {
    Notification::fake();

    $decision = app(IntegrityDecisions::class)->decide($this->mine, $this->trainer, 'warned', 'Copied answer on question 2.', 'Second time this month.');

    $warning = StudentWarning::query()->sole();

    expect($warning)
        ->user_id->toBe($this->meera->id)
        ->level->toBe('notice')
        ->reason->toBe('About your attempt at Keys and joins: Copied answer on question 2.')
        ->and($decision->warning_id)->toBe($warning->id);

    Notification::assertNotSentTo($this->meera, IntegrityDecisionMade::class);
});

/* --------------------------------------------------------- the screens */

it('shows a trainer the flagged attempts and one in full, and nobody else', function () {
    QuizAiReview::query()->create([
        'quiz_attempt_id' => $this->mine->id, 'status' => 'done', 'risk' => 'high', 'summary' => 'Worth asking about.',
        'evidence' => [['points_at' => 'Question 2', 'what' => 'Same words.']], 'explanations' => ['Same notes.'], 'finished_at' => now(),
    ]);

    $this->actingAs($this->trainer)
        ->get("/admin/courses/{$this->course->id}/quizzes/{$this->quiz->id}/integrity")
        ->assertInertia(fn ($page) => $page
            ->component('admin/assessment/Integrity')
            ->has('attempts', 2)
            ->where('attempts.0.student', 'Meera Iyer')
            ->where('attempts.0.ai.risk', 'high'));

    $this->actingAs($this->trainer)
        ->get("/admin/quiz-attempts/{$this->mine->id}/integrity")
        ->assertInertia(fn ($page) => $page
            ->component('admin/assessment/IntegrityAttempt')
            ->where('flags.0.with.student', 'Zoya Khan')
            ->where('answers.1.others.0.student', 'Zoya Khan')
            ->where('reviews.0.summary', 'Worth asking about.')
            ->has('timeline'));

    // Asking the AI about one attempt queues it once.
    $this->actingAs($this->trainer)->post("/admin/quiz-attempts/{$this->theirs->id}/ai-review")->assertSessionHas('success');
    $this->actingAs($this->trainer)->post("/admin/quiz-attempts/{$this->theirs->id}/ai-review")->assertSessionHas('success', 'Already waiting for an answer.');
    expect($this->theirs->aiReviews()->count())->toBe(1)->and($this->theirs->aiReviews()->value('requested_by'))->toBe($this->trainer->id);

    // Checking again leaves what is already asked or answered alone.
    $this->actingAs($this->trainer)
        ->post("/admin/courses/{$this->course->id}/quizzes/{$this->quiz->id}/integrity/check")
        ->assertSessionHas('success');
    expect(QuizAiReview::query()->count())->toBe(2);

    $outsider = User::factory()->admin()->create();
    $this->actingAs($outsider)->get("/admin/quiz-attempts/{$this->mine->id}/integrity")->assertForbidden();
    $this->actingAs($this->meera)->get("/admin/quiz-attempts/{$this->mine->id}/integrity")->assertRedirect();

});

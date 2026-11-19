<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Lms\AttemptEvents;
use App\Services\Lms\IntegrityChecks;
use App\Services\Lms\QuizGrader;

/*
 * Quiz integrity, layers one and two (§9.1): a paper fixed on each attempt,
 * answers kept as they are given with the time they took, what the browser
 * saw, and checks that find something for a person to look at without
 * deciding anything themselves.
 */

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create();

    $this->course = Course::query()->create([
        'title' => 'Databases', 'slug' => 'integrity-'.uniqid(), 'type' => 'course',
        'tagline' => 'Tables that hold', 'summary' => 'Six weeks.',
    ]);
    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id, 'name' => 'December batch', 'code' => 'IN-'.uniqid(), 'starts_on' => today()->subWeek(),
    ]);
    Enrollment::query()->create([
        'user_id' => $this->student->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeek(), 'has_paid' => true,
    ]);

    $this->quiz = Quiz::query()->create([
        'course_id' => $this->course->id, 'title' => 'Keys and joins', 'time_limit_minutes' => 20,
        'attempts_allowed' => 3, 'pass_percent' => 50, 'shuffle_questions' => false, 'is_published' => true,
    ]);

    $this->questions = collect([
        ['What does a primary key guarantee?', ['Speed', 'Uniqueness', 'Sorting', 'Compression'], 'Uniqueness'],
        ['Which join keeps unmatched rows from the left table?', ['Inner', 'Left', 'Cross', 'Natural'], 'Left'],
        ['Which keyword removes duplicate rows from a result?', ['UNIQUE', 'DISTINCT', 'SINGLE', 'ONLY'], 'DISTINCT'],
        // Options that are themselves numbers: an answer is the words, never a place in the list.
        ['How many primary keys can a table have?', ['0', '1', '2', 'Any number'], '1'],
        ['Which clause filters groups?', ['WHERE', 'HAVING', 'ORDER BY', 'LIMIT'], 'HAVING'],
        ['Which statement changes existing rows?', ['INSERT', 'UPDATE', 'ALTER', 'GRANT'], 'UPDATE'],
    ])->map(fn (array $question, int $index) => Question::query()->create([
        'quiz_id' => $this->quiz->id, 'type' => 'mcq', 'body' => $question[0], 'options' => $question[1],
        'correct' => [$question[2]], 'marks' => 1, 'sort_order' => $index,
    ]));

    $this->key = $this->questions->mapWithKeys(fn (Question $question) => [$question->id => $question->correct[0]])->all();
    $this->url = fn (QuizAttempt $attempt, string $rest = '') => "/student/quizzes/{$this->quiz->id}/attempts/{$attempt->id}{$rest}";
    $this->begin = function (): QuizAttempt {
        $this->actingAs($this->student)->post("/student/quizzes/{$this->quiz->id}/start")->assertRedirect();

        return QuizAttempt::query()->where('user_id', $this->student->id)->whereNull('submitted_at')->sole();
    };
});

/* ------------------------------------------------------------- the paper */

it('draws a pool once, when the attempt starts, and marks against that paper', function () {
    $this->quiz->update(['pool_size' => 4]);

    $attempt = ($this->begin)();
    $paper = array_map('intval', $attempt->question_ids);

    expect($paper)->toHaveCount(4)
        ->and($attempt->total_marks)->toBe(4);

    $shown = fn () => collect($this->actingAs($this->student)->get(($this->url)($attempt))->viewData('page')['props']['questions'])->pluck('id')->all();

    expect($shown())->toBe($paper)->and($shown())->toBe($paper);

    // Every drawn question right is full marks, however many the quiz holds.
    $this->actingAs($this->student)->post(($this->url)($attempt), ['answers' => array_intersect_key($this->key, array_flip($paper))]);

    expect($attempt->fresh()->score)->toBe(4)
        ->and((float) $attempt->fresh()->percent)->toBe(100.0);

    // A question that was not drawn cannot be answered into the attempt.
    $second = ($this->begin)();
    $undrawn = $this->questions->first(fn (Question $question) => ! in_array($question->id, array_map('intval', $second->question_ids), true));

    $this->actingAs($this->student)
        ->postJson(($this->url)($second, '/answers'), ['question_id' => $undrawn->id, 'response' => $undrawn->correct[0]])
        ->assertStatus(422)
        ->assertJson(['message' => 'That question is not on this paper.']);
});

it('shows each attempt its own option order and marks the words, not their place', function () {
    $this->quiz->update(['shuffle_options' => true]);

    $attempt = ($this->begin)();

    expect($attempt->option_orders)->toHaveCount(6);

    $questions = $this->actingAs($this->student)->get(($this->url)($attempt))->viewData('page')['props']['questions'];

    foreach ($questions as $shown) {
        $question = $this->questions->firstWhere('id', $shown['id']);

        expect($shown['options'])->toBe(array_map(fn (int $index) => $question->options[$index], $attempt->optionOrderFor($question)))
            ->and($shown)->not->toHaveKey('correct');
    }

    $this->actingAs($this->student)->post(($this->url)($attempt), ['answers' => $this->key])->assertRedirect(($this->url)($attempt, '/review'));

    expect($attempt->fresh()->score)->toBe(6);
});

it('tells the student the rules before they start', function () {
    $this->quiz->update(['pool_size' => 4, 'one_at_a_time' => true, 'allow_back' => false]);

    $this->actingAs($this->student)
        ->get("/student/quizzes/{$this->quiz->id}")
        ->assertInertia(fn ($page) => $page
            ->where('quiz.questionCount', 4)
            ->where('quiz.rules', fn ($rules) => collect($rules)->contains(fn ($rule) => str_contains($rule, 'once you move on you cannot go back'))
                && collect($rules)->contains(fn ($rule) => str_contains($rule, 'drawn from a larger set'))
                && collect($rules)->contains(fn ($rule) => str_contains($rule, 'Nothing noted here fails you'))));
});

/* -------------------------------------------------- answers, as they come */

it('keeps each answer as it is given, with the time spent on it', function () {
    [$first, $second] = [$this->questions[0], $this->questions[1]];
    $attempt = ($this->begin)();

    foreach ([[$first, 'Speed', 12], [$first, 'Uniqueness', 5], [$second, 'Left', 999999]] as [$question, $response, $seconds]) {
        $this->actingAs($this->student)
            ->postJson(($this->url)($attempt, '/answers'), ['question_id' => $question->id, 'response' => $response, 'seconds' => $seconds])
            ->assertOk()
            ->assertJson(['saved' => true]);
    }

    $kept = $attempt->answers()->get()->keyBy('question_id');

    expect($kept[$first->id])->response->toBe(['Uniqueness'])->seconds_spent->toBe(17)->answered_at->not->toBeNull()
        ->and($kept[$second->id]->seconds_spent)->toBe(QuizGrader::MAX_SECONDS_PER_SAVE)
        ->and($attempt->events()->where('type', 'answered')->count())->toBe(3);

    // A refresh brings them back to the page.
    $this->actingAs($this->student)
        ->get(($this->url)($attempt))
        ->assertInertia(fn ($page) => $page->where("saved.{$first->id}", 'Uniqueness')->where("saved.{$second->id}", 'Left'));

    // Submitting with nothing still marks what was kept.
    $this->actingAs($this->student)->post(($this->url)($attempt), []);

    expect($attempt->fresh()->score)->toBe(2);
});

it('refuses an answer once the time is up', function () {
    $attempt = ($this->begin)();

    $this->travel(20)->minutes();
    $this->travel(QuizGrader::GRACE_SECONDS + 1)->seconds();

    $this->actingAs($this->student)
        ->postJson(($this->url)($attempt, '/answers'), ['question_id' => $this->questions[0]->id, 'response' => 'Uniqueness'])
        ->assertStatus(422)
        ->assertJson(['message' => 'This attempt is closed.']);
});

it('shows one question at a time and closes each one as the student moves on', function () {
    $this->quiz->update(['one_at_a_time' => true, 'allow_back' => false]);
    $attempt = ($this->begin)();
    $paper = array_map('intval', $attempt->question_ids);

    $this->actingAs($this->student)
        ->get(($this->url)($attempt))
        ->assertInertia(fn ($page) => $page
            ->has('questions', 1)
            ->where('questions.0.id', $paper[0])
            ->where('attempt.currentIndex', 0)
            ->where('attempt.total', 6));

    // A wrong answer, and on to the next.
    $this->actingAs($this->student)
        ->post(($this->url)($attempt, '/next'), ['question_id' => $paper[0], 'response' => 'Speed', 'seconds' => 8])
        ->assertRedirect(($this->url)($attempt));

    expect($attempt->fresh()->current_index)->toBe(1);

    $this->actingAs($this->student)
        ->get(($this->url)($attempt))
        ->assertInertia(fn ($page) => $page->has('questions', 1)->where('questions.0.id', $paper[1]));

    // The first is closed: not by saving it, and not by the submission either.
    $this->actingAs($this->student)
        ->postJson(($this->url)($attempt, '/answers'), ['question_id' => $paper[0], 'response' => $this->key[$paper[0]]])
        ->assertStatus(422);

    $this->actingAs($this->student)->post(($this->url)($attempt), ['answers' => [
        $paper[0] => $this->key[$paper[0]],
        $paper[1] => $this->key[$paper[1]],
    ]]);

    $answers = $attempt->answers()->get()->keyBy('question_id');

    expect($answers[$paper[0]]->is_correct)->toBeFalse()
        ->and($answers[$paper[1]]->is_correct)->toBeTrue()
        ->and($attempt->fresh()->score)->toBe(1);
});

it('lets a student step back and change an answer where the quiz allows it', function () {
    $this->quiz->update(['one_at_a_time' => true, 'allow_back' => true]);
    $attempt = ($this->begin)();
    $first = $this->questions[0];

    $this->actingAs($this->student)
        ->get(($this->url)($attempt))
        ->assertInertia(fn ($page) => $page->has('questions', 6)->where('quiz.allowBack', true));

    $this->actingAs($this->student)->postJson(($this->url)($attempt, '/answers'), ['question_id' => $first->id, 'response' => 'Speed'])->assertOk();
    $this->actingAs($this->student)->postJson(($this->url)($attempt, '/answers'), ['question_id' => $this->questions[3]->id, 'response' => '1'])->assertOk();
    $this->actingAs($this->student)->postJson(($this->url)($attempt, '/answers'), ['question_id' => $first->id, 'response' => 'Uniqueness'])->assertOk();

    $this->actingAs($this->student)->post(($this->url)($attempt), []);

    expect($attempt->fresh()->score)->toBe(2);
});

it('keeps the answers back until the quiz has closed for everybody', function () {
    $this->quiz->update(['show_answers' => true, 'answers_after_close' => true, 'closes_at' => now()->addDay()]);
    $attempt = app(QuizGrader::class)->start($this->quiz, $this->student, $this->batch->id);
    app(QuizGrader::class)->submit($attempt, [$this->questions[0]->id => 'Uniqueness']);

    $this->actingAs($this->student)
        ->get(($this->url)($attempt, '/review'))
        ->assertInertia(fn ($page) => $page->where('answersWaitForClose', true)->has('answers', 0));

    $this->travel(2)->days();

    $this->actingAs($this->student)
        ->get(($this->url)($attempt, '/review'))
        ->assertInertia(fn ($page) => $page
            ->where('answersWaitForClose', false)
            ->has('answers', 6)
            ->where('answers.0.question', $this->questions[0]->body)
            ->where('answers.0.correct', ['Uniqueness'])
            ->where('answers.0.isCorrect', true));
});

it('needs a closing time before answers can wait for it', function () {
    $this->actingAs($this->owner)
        ->put("/admin/courses/{$this->course->id}/quizzes/{$this->quiz->id}", [
            'title' => 'Keys and joins', 'attempts_allowed' => 2, 'pass_percent' => 50,
            'show_answers' => true, 'answers_after_close' => true,
        ])
        ->assertSessionHasErrors(['closes_at' => 'Answers after it closes need a closing time.']);

    $this->actingAs($this->owner)
        ->put("/admin/courses/{$this->course->id}/quizzes/{$this->quiz->id}", [
            'title' => 'Keys and joins', 'attempts_allowed' => 2, 'pass_percent' => 50,
            'pool_size' => 4, 'shuffle_options' => true, 'one_at_a_time' => true,
            'show_answers' => true, 'answers_after_close' => true, 'closes_at' => now()->addWeek()->toDateTimeString(),
        ])
        ->assertSessionHasNoErrors();

    // Going back is allowed unless the form says it is not.
    expect($this->quiz->fresh())
        ->pool_size->toBe(4)
        ->shuffle_options->toBeTrue()
        ->one_at_a_time->toBeTrue()
        ->allow_back->toBeTrue()
        ->answers_after_close->toBeTrue();
});

/* ------------------------------------------------- one browser at a time */

it('notes carrying on in another browser and stops the first one answering', function () {
    $attempt = ($this->begin)();
    $first = session('quiz_device');

    // Another browser: no cookie, and a session of its own.
    session()->forget('quiz_device');

    $this->actingAs($this->student)
        ->get(($this->url)($attempt))
        ->assertInertia(fn ($page) => $page->where('attempt.elsewhere', true)->has('questions', 0));

    $this->actingAs($this->student)
        ->postJson(($this->url)($attempt, '/answers'), ['question_id' => $this->questions[0]->id, 'response' => 'Uniqueness'])
        ->assertStatus(409);

    $this->actingAs($this->student)->post(($this->url)($attempt, '/here'))->assertRedirect(($this->url)($attempt));

    expect($attempt->events()->where('type', 'device_changed')->count())->toBe(1);

    $this->actingAs($this->student)
        ->get(($this->url)($attempt))
        ->assertInertia(fn ($page) => $page->where('attempt.elsewhere', false)->has('questions', 6));

    // The first browser is now the other one: it can neither answer nor submit.
    session()->put('quiz_device', $first);

    $this->actingAs($this->student)
        ->postJson(($this->url)($attempt, '/answers'), ['question_id' => $this->questions[0]->id, 'response' => 'Uniqueness'])
        ->assertStatus(409);

    $this->actingAs($this->student)
        ->post(($this->url)($attempt), ['answers' => $this->key])
        ->assertRedirect(($this->url)($attempt))
        ->assertSessionHas('error');

    expect($attempt->fresh()->submitted_at)->toBeNull();
});

it('keeps the same browser across signing out and in, by its cookie', function () {
    $attempt = ($this->begin)();
    $token = session('quiz_device');

    // Signing out empties the session; the cookie is still there.
    session()->flush();

    $this->actingAs($this->student)
        ->withCookie('quiz_device', $token)
        ->get(($this->url)($attempt))
        ->assertInertia(fn ($page) => $page->where('attempt.elsewhere', false)->has('questions', 6));
});

/* ------------------------------------------------- what the browser saw */

it('keeps only what is the browser\'s to report', function () {
    $attempt = ($this->begin)();

    $this->actingAs($this->student)
        ->postJson(($this->url)($attempt, '/events'), ['events' => [
            ['type' => 'focus_lost'],
            ['type' => 'focus_back', 'seconds' => 42],
            ['type' => 'paste', 'question_id' => $this->questions[0]->id, 'length' => 180],
            ['type' => 'submitted'],
            ['type' => 'device_changed'],
            ['type' => 'copy', 'question_id' => 999999, 'length' => 5],
        ]])
        ->assertOk()
        ->assertJson(['kept' => 4]);

    $events = $attempt->events()->get();

    expect($events->pluck('type')->all())->toBe(['started', 'focus_lost', 'focus_back', 'paste', 'copy'])
        ->and($events->firstWhere('type', 'focus_back')->duration_seconds)->toBe(42)
        ->and($events->firstWhere('type', 'paste')->detail)->toBe(['length' => 180])
        ->and($events->firstWhere('type', 'copy')->question_id)->toBeNull();

    $this->actingAs($this->student)
        ->postJson(($this->url)($attempt, '/events'), ['events' => array_fill(0, AttemptEvents::PER_REPORT + 1, ['type' => 'copy'])])
        ->assertStatus(422);

    // Nothing more once it is submitted.
    $this->actingAs($this->student)->post(($this->url)($attempt), ['answers' => $this->key]);

    $this->actingAs($this->student)
        ->postJson(($this->url)($attempt, '/events'), ['events' => [['type' => 'focus_lost']]])
        ->assertJson(['kept' => 0]);
});

it('notes a change of network without refusing it', function () {
    $attempt = ($this->begin)();

    $this->actingAs($this->student)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get(($this->url)($attempt))
        ->assertOk();

    expect($attempt->fresh()->last_ip)->toBe('203.0.113.9')
        ->and($attempt->events()->where('type', 'ip_changed')->sole()->detail)->toBe(['from' => '127.0.0.1']);
});

/* ------------------------------------------------------------ the checks */

it('flags a paper answered faster than it can be read, either way it is set', function () {
    $grader = app(QuizGrader::class);
    $checks = app(IntegrityChecks::class);

    // One question at a time: the seconds on each one count.
    $this->quiz->update(['one_at_a_time' => true, 'allow_back' => true]);
    $quick = $grader->start($this->quiz, $this->student, $this->batch->id);

    foreach ($this->questions as $question) {
        $grader->saveAnswer($quick, $question->id, $question->correct[0], 2);
    }

    $grader->submit($quick, []);
    $checks->run($this->quiz);

    expect(collect($quick->fresh()->flags)->pluck('type')->all())->toBe(['speed'])
        ->and($quick->fresh()->score)->toBe(6);

    // Every question on one page: only the whole sitting counts, because
    // people read ahead and then answer in a run.
    $this->quiz->update(['one_at_a_time' => false]);
    $reader = User::factory()->student()->create();
    $steady = $grader->start($this->quiz, $reader, $this->batch->id);
    $this->travel(5)->minutes();

    foreach ($this->questions as $question) {
        $grader->saveAnswer($steady, $question->id, $question->correct[0], 1);
    }

    $grader->submit($steady, []);

    $racer = User::factory()->student()->create();
    $fast = $grader->start($this->quiz, $racer, $this->batch->id);
    $this->travel(10)->seconds();
    $grader->submit($fast, $this->key);

    $checks->run($this->quiz);

    $speed = fn (QuizAttempt $attempt) => collect($attempt->fresh()->flags)->firstWhere('type', 'speed');

    expect($speed($steady))->toBeNull()
        ->and($speed($fast)['summary'])->toBe('6 questions answered in 10 seconds in all, with 100% right.');
});

it('flags a long paste, a right answer straight after time away, and another browser', function () {
    $this->quiz->update(['one_at_a_time' => true, 'allow_back' => true]);
    $written = Question::query()->create([
        'quiz_id' => $this->quiz->id, 'type' => 'short', 'body' => 'Why does a foreign key exist?', 'marks' => 4, 'sort_order' => 10,
    ]);

    $grader = app(QuizGrader::class);
    $events = app(AttemptEvents::class);
    $attempt = $grader->start($this->quiz, $this->student, $this->batch->id, hash('sha256', 'first'));

    $this->travel(1)->minutes();
    $events->fromBrowser($attempt, [['type' => 'focus_lost']], '127.0.0.1');
    $this->travel(50)->seconds();
    $events->fromBrowser($attempt, [['type' => 'focus_back', 'seconds' => 50]], '127.0.0.1');
    $this->travel(6)->seconds();
    $grader->saveAnswer($attempt, $this->questions[2]->id, 'DISTINCT', 60);

    $this->travel(1)->minutes();
    $events->fromBrowser($attempt, [['type' => 'paste', 'question_id' => $written->id, 'length' => 240]], '127.0.0.1');
    $grader->saveAnswer($attempt, $written->id, str_repeat('So a row cannot point at nothing. ', 7), 40);
    $events->notice($attempt, '127.0.0.1', hash('sha256', 'second'));

    $this->travel(1)->minutes();
    $grader->submit($attempt, []);
    app(IntegrityChecks::class)->run($this->quiz);

    $flags = collect($attempt->fresh()->flags)->keyBy('type');

    expect($flags->keys()->sort()->values()->all())->toBe(['answered_after_away', 'device', 'paste'])
        ->and($flags['answered_after_away']['evidence']['returns'][0])->toMatchArray(['question' => $this->questions[2]->id, 'away_seconds' => 50])
        ->and($flags['paste']['summary'])->toBe('Pasted 240 characters into an answer.')
        ->and($flags['device']['summary'])->toBe('Carried on in another browser once.')
        ->and($attempt->fresh()->flagged_at)->not->toBeNull();
});

it('flags pairs in a batch: written answers alike, and the same answers sent from one address', function () {
    $written = Question::query()->create([
        'quiz_id' => $this->quiz->id, 'type' => 'short', 'body' => 'Why does a foreign key exist?', 'marks' => 4, 'sort_order' => 10,
    ]);
    $grader = app(QuizGrader::class);
    $friend = User::factory()->student()->create();
    $stranger = User::factory()->student()->create();
    $text = 'A foreign key makes sure a row can only point at a record that really exists in the other table.';

    $mine = $grader->start($this->quiz, $this->student, $this->batch->id);
    $theirs = $grader->start($this->quiz, $friend, $this->batch->id);
    $other = $grader->start($this->quiz, $stranger, $this->batch->id);
    $this->travel(15)->minutes();

    // The same right answers sent from one address within minutes, and a
    // written answer that differs by a full stop.
    $grader->submit($mine, $this->key + [$written->id => $text]);
    $grader->submit($theirs, $this->key + [$written->id => 'A foreign key makes sure a row can only point at a record that really exists in the other table!']);
    $grader->submit($other, [$written->id => 'It keeps related tables consistent.']);

    app(IntegrityChecks::class)->run($this->quiz);

    $flagsOf = fn (QuizAttempt $attempt) => collect($attempt->fresh()->flags)->keyBy('type');

    expect($flagsOf($mine)->keys()->sort()->values()->all())->toBe(['network', 'overlap'])
        ->and($flagsOf($mine)['overlap']['evidence'])->toBe(['with' => $theirs->id, 'questions' => [$written->id]])
        ->and($flagsOf($theirs)['network']['evidence']['with'])->toBe($mine->id)
        ->and($other->fresh()->flags)->toBeNull();

    // Two people writing the same right idea in their own words is not a match.
    expect(app(IntegrityChecks::class)->similarity($text, 'Foreign keys stop orphaned rows: every reference has to match a real row elsewhere.'))->toBeLessThan(0.2);
});

it('checks a quiz once it has closed and the last sitting is over', function () {
    $this->quiz->update(['closes_at' => now()->addMinutes(5)]);
    $grader = app(QuizGrader::class);
    $attempt = $grader->start($this->quiz, $this->student, $this->batch->id);
    $grader->submit($attempt, $this->key);

    $this->travel(10)->minutes();

    // Closed, but somebody who started just before could still be sitting it.
    $this->artisan('quizzes:check-integrity')->assertSuccessful();
    expect($this->quiz->fresh()->integrity_checked_at)->toBeNull();

    $this->travel(20)->minutes();
    $this->artisan('quizzes:check-integrity')->expectsOutputToContain('Checked 1 quiz')->assertSuccessful();
    $checked = $this->quiz->fresh()->integrity_checked_at;

    expect($checked)->not->toBeNull();

    $this->travel(1)->hour();
    $this->artisan('quizzes:check-integrity')->expectsOutputToContain('Checked 0 quizzes')->assertSuccessful();
    expect($this->quiz->fresh()->integrity_checked_at->equalTo($checked))->toBeTrue();
});

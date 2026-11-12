<?php

use App\Models\Assignment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptGrant;
use App\Models\Submission;
use App\Models\SubmissionVersion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyDatabase;

beforeEach(function () {
    LegacyDatabase::create();
    LegacyDatabase::createDelivery();
    LegacyDatabase::createAssessment();
    Storage::fake('local');
    Storage::fake('private');

    $this->student = LegacyDatabase::user(['email' => 'learner@example.test']);
    $this->trainer = LegacyDatabase::user(['email' => 'trainer@example.test', 'role' => 'admin']);
    $program = LegacyDatabase::insert('training_programs', ['title' => 'Data course', 'slug' => 'data-course']);
    $this->batch = LegacyDatabase::insert('batches', ['program_id' => $program, 'name' => 'June']);

    $this->quiz = LegacyDatabase::insert('quizzes', [
        'batch_id' => $this->batch, 'title' => 'Week 1 check', 'time_limit_minutes' => 20, 'passing_score' => 60,
        'max_attempts' => 2, 'is_published' => true,
    ]);
    $this->mcq = LegacyDatabase::insert('quiz_questions', ['quiz_id' => $this->quiz, 'question' => 'Where is a form validated?', 'type' => 'mcq', 'marks' => 2, 'sort_order' => 1,
        'options' => json_encode([['text' => 'Browser only', 'is_correct' => false], ['text' => 'On the server', 'is_correct' => true]])]);
    $this->multi = LegacyDatabase::insert('quiz_questions', ['quiz_id' => $this->quiz, 'question' => 'Which are secrets?', 'type' => 'multiple', 'marks' => 3, 'sort_order' => 2,
        'options' => json_encode([['text' => 'DB password', 'is_correct' => true], ['text' => 'Page title', 'is_correct' => false], ['text' => 'API key', 'is_correct' => true]])]);
    $this->tf = LegacyDatabase::insert('quiz_questions', ['quiz_id' => $this->quiz, 'question' => 'Edit a migration after it ran?', 'type' => 'truefalse', 'marks' => 1, 'correct_answer' => json_encode(['false'])]);
    $this->short = LegacyDatabase::insert('quiz_questions', ['quiz_id' => $this->quiz, 'question' => 'Why a foreign key?', 'type' => 'short', 'marks' => 4, 'correct_answer' => json_encode(['Integrity'])]);
    $this->file = LegacyDatabase::insert('quiz_questions', ['quiz_id' => $this->quiz, 'question' => 'Upload your diagram', 'type' => 'file', 'marks' => 5]);

    $this->storage = LegacyDatabase::storage(['app/private/submissions/1/v1.zip' => 'PK first', 'app/private/submissions/1/v2.zip' => 'PK second']);
});

afterEach(fn () => File::deleteDirectory($this->storage));

function importAssessment($test): array
{
    $test->artisan('legacy:import', ['--all-generation-one' => true, '--storage' => $test->storage])->assertSuccessful();
    $files = Storage::disk('local')->files('legacy-reports');
    sort($files);

    return json_decode(Storage::disk('local')->get(end($files)), true);
}

it('brings a quiz across with its questions in the v3 shape, and lists what v3 cannot ask', function () {
    $report = importAssessment($this);

    $quiz = Quiz::query()->sole();
    expect($quiz->batch_id)->not->toBeNull()
        ->and($quiz->pass_percent)->toBe(60)
        ->and($quiz->attempts_allowed)->toBe(2)
        ->and($quiz->is_published)->toBeTrue();

    $mcq = Question::query()->where('body', 'Where is a form validated?')->sole();
    expect($mcq->options)->toBe(['Browser only', 'On the server'])->and($mcq->correct)->toBe(['On the server']);

    $multi = Question::query()->where('type', 'multi')->sole();
    expect($multi->correct)->toBe(['DB password', 'API key']);

    $tf = Question::query()->where('type', 'truefalse')->sole();
    expect($tf->options)->toBe(['True', 'False'])->and($tf->correct)->toBe(['False']);

    $short = Question::query()->where('type', 'short')->sole();
    expect($short->correct)->toBeNull()->and($short->explanation)->toBe('Expected answer: Integrity');

    expect(Question::query()->count())->toBe(4)
        ->and(collect($report['steps']['quizzes']['warnings'])->where('code', 'question_type_unsupported')->pluck('source')->all())->toBe(["quiz_questions#{$this->file}"]);
});

it('copies attempts with their scores untouched, answers as the options chosen, and waits for a person where v2 did', function () {
    $marked = LegacyDatabase::insert('quiz_attempts', ['quiz_id' => $this->quiz, 'student_id' => $this->student, 'attempt_number' => 1, 'started_at' => '2026-06-01 10:00:00', 'submitted_at' => '2026-06-01 10:15:00',
        'score' => '4.50', 'total_marks' => '10', 'percentage' => '45.00', 'is_graded' => true]);
    LegacyDatabase::insert('quiz_answers', ['attempt_id' => $marked, 'question_id' => $this->mcq, 'answer' => json_encode([1]), 'is_correct' => true, 'marks_awarded' => 2]);
    LegacyDatabase::insert('quiz_answers', ['attempt_id' => $marked, 'question_id' => $this->multi, 'answer' => json_encode([0, 2]), 'is_correct' => true, 'marks_awarded' => 3]);
    LegacyDatabase::insert('quiz_answers', ['attempt_id' => $marked, 'question_id' => $this->tf, 'answer' => json_encode(['true']), 'is_correct' => false, 'marks_awarded' => 0]);
    $waiting = LegacyDatabase::insert('quiz_attempts', ['quiz_id' => $this->quiz, 'student_id' => $this->student, 'attempt_number' => 2, 'started_at' => '2026-06-02 10:00:00', 'submitted_at' => '2026-06-02 10:10:00',
        'score' => '5', 'total_marks' => '10', 'percentage' => '50', 'is_graded' => false]);
    LegacyDatabase::insert('quiz_attempt_overrides', ['quiz_id' => $this->quiz, 'student_id' => $this->student, 'extra_attempts' => 1, 'granted_by' => $this->trainer]);

    importAssessment($this);

    $first = QuizAttempt::query()->where('attempt_number', 1)->sole();
    expect($first->score)->toBe(5)
        ->and((float) $first->percent)->toBe(45.0)
        ->and($first->passed)->toBeFalse()
        ->and($first->needs_review)->toBeFalse()
        ->and($first->expires_at->toDateTimeString())->toBe('2026-06-01 10:20:00');

    expect(QuizAnswer::query()->whereHas('question', fn ($q) => $q->where('type', 'mcq'))->sole()->response)->toBe(['On the server'])
        ->and(QuizAnswer::query()->whereHas('question', fn ($q) => $q->where('type', 'multi'))->sole()->response)->toBe(['DB password', 'API key'])
        ->and(QuizAnswer::query()->whereHas('question', fn ($q) => $q->where('type', 'truefalse'))->sole()->response)->toBe(['True']);

    expect(QuizAttempt::query()->where('attempt_number', 2)->sole()->needs_review)->toBeTrue();

    $grant = QuizAttemptGrant::query()->sole();
    expect($grant->reason)->toBe('Granted on the old site; no reason recorded.')
        ->and($grant->granted_by)->not->toBeNull();
});

it('keeps every version of a submission, with marks and feedback, and copies each file', function () {
    $assignment = LegacyDatabase::insert('assignments', ['batch_id' => $this->batch, 'title' => 'Schema design', 'description' => '<p>Design it.</p>', 'due_at' => '2026-06-10 23:59:00', 'max_marks' => 20]);
    LegacyDatabase::insert('assignment_submissions', ['assignment_id' => $assignment, 'student_id' => $this->student, 'submission_number' => 1, 'is_latest' => false,
        'file_path' => 'submissions/1/v1.zip', 'original_name' => 'first.zip', 'submitted_at' => '2026-06-09 10:00:00', 'marks' => '8.5', 'feedback' => 'Missing indexes', 'graded_at' => '2026-06-09 12:00:00', 'graded_by' => $this->trainer]);
    LegacyDatabase::insert('assignment_submissions', ['assignment_id' => $assignment, 'student_id' => $this->student, 'submission_number' => 2, 'is_latest' => true,
        'file_path' => 'submissions/1/v2.zip', 'original_name' => 'second.zip', 'submitted_at' => '2026-06-11 10:00:00']);

    importAssessment($this);

    expect(Assignment::query()->sole()->brief)->toBe('Design it.');

    $submission = Submission::query()->sole();
    expect($submission->version)->toBe(2)
        ->and($submission->status)->toBe('submitted')
        ->and($submission->is_late)->toBeTrue()
        ->and($submission->files[0]['name'])->toBe('second.zip')
        ->and(Storage::disk('private')->get($submission->files[0]['path']))->toBe('PK second');

    $earlier = SubmissionVersion::query()->sole();
    expect($earlier->version)->toBe(1)
        ->and($earlier->status)->toBe('evaluated')
        ->and($earlier->marks)->toBe(9)
        ->and($earlier->feedback)->toBe('Missing indexes')
        ->and(Storage::disk('private')->get($earlier->files[0]['path']))->toBe('PK first');
});

it('runs again without duplicating attempts, answers or submissions', function () {
    $attempt = LegacyDatabase::insert('quiz_attempts', ['quiz_id' => $this->quiz, 'student_id' => $this->student, 'attempt_number' => 1, 'started_at' => now(), 'submitted_at' => now(), 'score' => 2, 'total_marks' => 10, 'percentage' => 20, 'is_graded' => true]);
    LegacyDatabase::insert('quiz_answers', ['attempt_id' => $attempt, 'question_id' => $this->mcq, 'answer' => json_encode([0]), 'is_correct' => false]);

    importAssessment($this);
    importAssessment($this);

    expect(Quiz::query()->count())->toBe(1)
        ->and(Question::query()->count())->toBe(4)
        ->and(QuizAttempt::query()->count())->toBe(1)
        ->and(QuizAnswer::query()->count())->toBe(1);
});

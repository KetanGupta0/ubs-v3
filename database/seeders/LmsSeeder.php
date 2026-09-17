<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\College;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LiveSession;
use App\Models\Material;
use App\Models\MentorReview;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\StudentWarning;
use App\Models\Submission;
use App\Models\User;
use App\Services\Lms\Activity;
use App\Services\Lms\Enroller;
use App\Services\Lms\Progress;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A worked example of the learning management system, for development.
 *
 * One internship half way through: a syllabus with every kind of lock on it, a
 * batch with classes behind and ahead, a register that has been marked, a quiz
 * with a written answer still to mark, an assignment waiting in the queue, a
 * notice sent to somebody who stopped turning up, and a certificate already
 * issued. Enough that no screen in the module opens empty.
 *
 * Safe to re-run: everything is matched on something stable and updated in
 * place, and the services are used rather than reimplemented so what is seeded
 * is what the application itself would have written.
 */
class LmsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('The worked example is never seeded in production.');

            return;
        }

        $course = Course::query()->internships()->where('is_published', true)->first()
            ?? Course::query()->taught()->first();

        if (! $course) {
            $this->command?->warn('No course to build a worked example on. Run the catalogue seeder first.');

            return;
        }

        $trainer = User::query()->role(Role::Admin)->first();
        $college = $this->seedCollege();

        $batch = $this->batchFor($course, $trainer);
        $modules = $this->seedSyllabus($course, $trainer);
        $quiz = $this->seedQuiz($course, $modules['first']);
        $assignment = $this->seedAssignment($course, $batch, $modules['second']);

        // The quiz gate is set after the quiz exists, because a lesson cannot
        // point at a quiz that has not been created yet.
        $modules['gated']->forceFill([
            'required_quiz_id' => $quiz->id,
            'min_quiz_score' => 60,
        ])->save();

        $students = $this->seedStudents($course, $batch, $college);
        $sessions = $this->seedSessions($batch, $trainer);

        $this->seedRegisters($sessions, $students, $trainer, $batch);
        $this->seedProgress($students, $course, $batch);
        $this->seedQuizAttempt($quiz, $students['keen'], $batch);
        $this->seedSubmission($assignment, $students['keen']);
        $this->seedAnnouncements($course, $batch, $trainer);
        $this->seedWarning($students['drifting'], $batch, $trainer);
        $this->seedReview($students['keen'], $batch, $trainer);

        $this->command?->info("Worked example ready on {$batch->name} ({$course->title}).");
    }

    /**
     * A college to hang the tie-up side of the module on.
     *
     * Invented, and obviously so: a real institution's name on a demonstration
     * record is a claim about a relationship we do not have.
     */
    protected function seedCollege(): College
    {
        return College::query()->updateOrCreate(
            ['slug' => 'example-institute-of-technology'],
            [
                'name' => 'Example Institute of Technology',
                'short_name' => 'EIT',
                'city' => 'Pune',
                'state' => 'Maharashtra',
                'university' => 'Example State Technical University',
                'coordinator_name' => 'Dr S. Kulkarni',
                'coordinator_email' => 'placements@example.test',
                'coordinator_mobile' => '+919000000200',
                'mou_signed_on' => today()->subMonths(8),
                'mou_expires_on' => today()->addMonths(4),
                'notes' => 'Sends a batch each semester. Placement cell wants the monthly report by the fifth.',
                'is_active' => true,
            ],
        );
    }

    protected function batchFor(Course $course, ?User $trainer): Batch
    {
        $batch = $course->batches()->orderBy('starts_on')->first()
            ?? Batch::query()->create([
                'course_id' => $course->id,
                'name' => 'Worked example batch',
                'code' => 'EXAMPLE-'.Str::upper(Str::random(4)),
                'capacity' => 24,
            ]);

        // Half way through, so both the drip locks and the attendance figures
        // have something to say.
        $batch->forceFill([
            'trainer_id' => $trainer?->id,
            'starts_on' => today()->subWeeks(4),
            'ends_on' => today()->addWeeks(4),
            'status' => 'running',
            'meet_link' => 'https://meet.google.com/ubs-demo-001',
            'college_id' => College::query()->value('id'),
        ])->save();

        return $batch;
    }

    /**
     * Two modules, and a third that is still sealed.
     *
     * @return array{first: CourseModule, second: CourseModule, gated: Lesson}
     */
    protected function seedSyllabus(Course $course, ?User $trainer): array
    {
        $plan = [
            [
                'title' => 'Week one — how a web application is put together',
                'summary' => 'The parts, what talks to what, and why it is arranged that way.',
                'unlock_after_days' => null,
                'lessons' => [
                    ['Request to response', 'What actually happens between a click and a page.', 35, ['is_preview' => true]],
                    ['Where the data lives', 'Tables, rows, relationships, and why a spreadsheet stops working.', 45, []],
                    ['Version control, properly', 'Branches, commits worth reading, and undoing the afternoon you regret.', 40, []],
                ],
            ],
            [
                'title' => 'Week two — building the thing',
                'summary' => 'Routes, forms, validation and the first screens of your own project.',
                'unlock_after_days' => 7,
                'lessons' => [
                    ['Routes and controllers', 'One place per job, and how to keep it that way.', 50, []],
                    ['Forms that refuse bad input', 'Validation on the server, because the browser is not a guard.', 55, []],
                    ['Your project, first screen', 'Start the thing you will hand in at the end.', 60, []],
                ],
            ],
            [
                'title' => 'Week three — shipping it',
                'summary' => 'Deployment, environments, and what breaks the first time somebody else uses it.',
                'unlock_after_days' => 21,
                'lessons' => [
                    ['Environments and secrets', 'Why the key never goes in the repository.', 45, ['requires_payment' => true]],
                    ['Putting it online', 'A deploy you can repeat, rather than one you remember.', 50, []],
                ],
            ],
        ];

        $modules = [];
        $previous = null;

        foreach ($plan as $index => $entry) {
            $module = CourseModule::query()->updateOrCreate(
                ['course_id' => $course->id, 'title' => $entry['title']],
                [
                    'summary' => $entry['summary'],
                    'sort_order' => $index,
                    'unlock_after_days' => $entry['unlock_after_days'],
                    'is_published' => true,
                ],
            );

            foreach ($entry['lessons'] as $position => [$title, $summary, $minutes, $extra]) {
                $lesson = Lesson::query()->updateOrCreate(
                    ['course_id' => $course->id, 'slug' => Str::slug($title)],
                    [
                        'course_module_id' => $module->id,
                        'title' => $title,
                        'summary' => $summary,
                        'content' => $summary."\n\nNotes for this session go here. In a real course this is what a "
                            .'student reads alongside the recording, so it is written to be read on its own.',
                        'duration_minutes' => $minutes,
                        'sort_order' => $position,
                        'is_published' => true,
                        // The third lesson of each module waits for the one
                        // before it, so the prerequisite rule is visible.
                        'prerequisite_lesson_id' => $position === 2 ? $previous?->id : null,
                        ...$extra,
                    ],
                );

                $previous = $lesson;
                $modules['lessons'][] = $lesson;
            }

            $modules[$index] = $module;
        }

        Material::query()->updateOrCreate(
            ['course_id' => $course->id, 'title' => 'Setup checklist'],
            [
                'description' => 'Everything to install before the first class, with the versions we use.',
                'external_url' => 'https://example.com/ubs/setup-checklist',
                'is_downloadable' => true,
                'uploaded_by' => $trainer?->id,
                'sort_order' => 0,
            ],
        );

        return [
            'first' => $modules[0],
            'second' => $modules[1],
            // The last lesson of week two is what the quiz gate hangs on.
            'gated' => $modules['lessons'][5],
        ];
    }

    protected function seedQuiz(Course $course, CourseModule $module): Quiz
    {
        $quiz = Quiz::query()->updateOrCreate(
            ['course_id' => $course->id, 'title' => 'Week one check'],
            [
                'course_module_id' => $module->id,
                'instructions' => 'Twelve minutes, one attempt in three. The clock is kept on our side, so closing '
                    .'the laptop does not stop it.',
                'time_limit_minutes' => 12,
                'attempts_allowed' => 3,
                'pass_percent' => 60,
                'shuffle_questions' => true,
                'show_answers' => true,
                'is_published' => true,
            ],
        );

        $questions = [
            ['mcq', 'Where should a form be validated?',
                ['In the browser only', 'On the server', 'Neither, if the field is required'],
                ['On the server'], 'The browser can be bypassed with one request, so it is help, not a guard.', 2],
            ['multi', 'Which of these belong in an environment file rather than the repository?',
                ['The database password', 'The page title', 'An API secret', 'The route list'],
                ['The database password', 'An API secret'],
                'Anything that differs per environment or would do damage if published.', 3],
            ['truefalse', 'A migration should be edited after it has run on production.',
                ['True', 'False'], ['False'],
                'Write a new one. The old file is the record of what actually happened.', 1],
            ['short', 'In your own words: why does a foreign key exist?',
                null, null, null, 4],
        ];

        foreach ($questions as $index => [$type, $body, $options, $correct, $explanation, $marks]) {
            Question::query()->updateOrCreate(
                ['quiz_id' => $quiz->id, 'body' => $body],
                [
                    'type' => $type,
                    'options' => $options,
                    'correct' => $correct,
                    'explanation' => $explanation,
                    'marks' => $marks,
                    'sort_order' => $index,
                ],
            );
        }

        return $quiz;
    }

    protected function seedAssignment(Course $course, Batch $batch, CourseModule $module): Assignment
    {
        return Assignment::query()->updateOrCreate(
            ['course_id' => $course->id, 'title' => 'Project — the first working screen'],
            [
                'course_module_id' => $module->id,
                'batch_id' => $batch->id,
                'brief' => 'Build the first screen of your project end to end: a route, a controller, a form that '
                    ."refuses bad input, and a record that is actually saved.\n\nHand in the repository link and a "
                    .'short note on what you would do differently with another week.',
                'checklist' => [
                    'Validation runs on the server',
                    'The record is saved and can be read back',
                    'Commits are small and their messages say what changed',
                    'The README says how to run it from nothing',
                ],
                'due_at' => now()->addDays(6)->setTime(23, 59),
                'max_marks' => 50,
                'allow_late' => true,
                'is_project' => true,
                'is_published' => true,
            ],
        );
    }

    /**
     * Four students, deliberately at different places.
     *
     * @return array<string, User>
     */
    protected function seedStudents(Course $course, Batch $batch, College $college): array
    {
        $enroller = app(Enroller::class);

        $people = [
            'keen' => ['Asha Verma', 'asha.verma@example.test', '+919000000101', '21CS045'],
            'steady' => ['Imran Qureshi', 'imran.qureshi@example.test', '+919000000102', '21CS062'],
            'drifting' => ['Neha Pillai', 'neha.pillai@example.test', '+919000000103', '21CS071'],
            'new' => ['Rohit Das', 'rohit.das@example.test', '+919000000104', '21CS088'],
        ];

        $students = [];

        foreach ($people as $key => [$name, $email, $mobile, $roll]) {
            $student = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'mobile' => $mobile,
                    'password' => DemoAccountSeeder::PASSWORD,
                    'role' => Role::Student,
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                ],
            );

            $student->profile()->updateOrCreate([], [
                'college_id' => $college->id,
                'enrollment_number' => $roll,
                'course_of_study' => 'B.Tech Computer Science',
                'current_semester' => 6,
                'graduation_year' => now()->addYear()->year,
            ]);

            $enroller->enrol($student, $course, $batch, source: 'college', waiveFee: true, notify: false);

            $students[$key] = $student;
        }

        // The demo student account joins too, so signing in as them lands on a
        // dashboard with something on it.
        if ($demo = User::query()->where('email', 'student@unboundbyte.test')->first()) {
            $enroller->enrol($demo, $course, $batch, source: 'admin', waiveFee: true, notify: false);
            $students['demo'] = $demo;
        }

        return $students;
    }

    /** @return array<int, LiveSession> */
    protected function seedSessions(Batch $batch, ?User $trainer): array
    {
        $plan = [
            ['Session 1 — request to response', -26, 'held'],
            ['Session 2 — where the data lives', -19, 'held'],
            ['Session 3 — version control', -12, 'held'],
            ['Session 4 — routes and controllers', -5, 'held'],
            ['Session 5 — forms and validation', 2, 'scheduled'],
            ['Session 6 — project review', 9, 'scheduled'],
        ];

        $sessions = [];

        foreach ($plan as [$title, $days, $status]) {
            $sessions[] = LiveSession::query()->updateOrCreate(
                ['batch_id' => $batch->id, 'title' => $title],
                [
                    'agenda' => 'Walkthrough, then everybody builds the same thing while we watch.',
                    'scheduled_at' => now()->addDays($days)->setTime(19, 30),
                    'duration_minutes' => 90,
                    'status' => $status,
                    'meet_link' => $batch->meet_link,
                    'recording_url' => $status === 'held' ? 'https://example.com/ubs/recordings/'.Str::slug($title) : null,
                    'trainer_id' => $trainer?->id,
                ],
            );
        }

        return $sessions;
    }

    /**
     * Registers for the classes already held.
     *
     * The drifting student misses most of them, which is what makes the notice
     * on the batch screen a real one rather than a demonstration.
     */
    protected function seedRegisters(array $sessions, array $students, ?User $trainer, Batch $batch): void
    {
        $activity = app(Activity::class);

        $pattern = [
            'keen' => ['present', 'present', 'present', 'present'],
            'steady' => ['present', 'late', 'present', 'present'],
            'drifting' => ['present', 'absent', 'absent', 'absent'],
            'new' => ['excused', 'present', 'absent', 'present'],
            'demo' => ['present', 'present', 'late', 'present'],
        ];

        foreach ($sessions as $index => $session) {
            if ($session->status !== 'held') {
                continue;
            }

            foreach ($pattern as $key => $marks) {
                $student = $students[$key] ?? null;

                if (! $student) {
                    continue;
                }

                $status = $marks[$index] ?? 'absent';

                $attendance = Attendance::query()->updateOrCreate(
                    ['live_session_id' => $session->id, 'user_id' => $student->id],
                    [
                        'status' => $status,
                        'marked_by' => $trainer?->id,
                        'marked_at' => $session->scheduled_at->copy()->addMinutes(95),
                        'note' => $status === 'excused' ? 'Told us beforehand — college examination.' : null,
                    ],
                );

                if (in_array($status, ['present', 'late'], true)) {
                    $activity->did(
                        $student,
                        'session.attended',
                        $session,
                        ['title' => $session->title],
                        $batch->course_id,
                        $batch->id,
                    );
                }

                unset($attendance);
            }
        }
    }

    /** Lessons finished, so progress bars and the leaderboard mean something. */
    protected function seedProgress(array $students, Course $course, Batch $batch): void
    {
        $progress = app(Progress::class);
        $activity = app(Activity::class);

        $lessons = Lesson::query()
            ->where('course_id', $course->id)
            ->orderBy('sort_order')
            ->get();

        $howMany = ['keen' => 6, 'steady' => 4, 'drifting' => 1, 'new' => 2, 'demo' => 3];

        foreach ($howMany as $key => $count) {
            $student = $students[$key] ?? null;

            if (! $student) {
                continue;
            }

            foreach ($lessons->take($count) as $lesson) {
                $lesson->completions()->firstOrCreate(
                    ['user_id' => $student->id],
                    ['completed_at' => now()->subDays(rand(1, 20))],
                );

                $activity->did(
                    $student,
                    'lesson.completed',
                    $lesson,
                    ['title' => $lesson->title],
                    $course->id,
                    $batch->id,
                );
            }

            $enrolment = Enrollment::query()
                ->where('user_id', $student->id)
                ->where('course_id', $course->id)
                ->first();

            if ($enrolment) {
                $progress->recalculate($enrolment);
            }
        }
    }

    /**
     * One sat attempt with the written answer still to mark.
     *
     * That is the state the marking queue exists for, and it is the one a
     * screenshot never shows because it is inconvenient to produce by hand.
     */
    protected function seedQuizAttempt(Quiz $quiz, User $student, Batch $batch): void
    {
        if (QuizAttempt::query()->where('quiz_id', $quiz->id)->where('user_id', $student->id)->exists()) {
            return;
        }

        $questions = $quiz->questions()->get();
        $total = (int) $questions->sum('marks');

        $attempt = QuizAttempt::query()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $student->id,
            'batch_id' => $batch->id,
            'attempt_number' => 1,
            'started_at' => now()->subDays(3),
            'expires_at' => now()->subDays(3)->addMinutes(12),
            'submitted_at' => now()->subDays(3)->addMinutes(9),
            'total_marks' => $total,
        ]);

        $scored = 0;

        foreach ($questions as $question) {
            $written = $question->type === 'short';

            $response = match ($question->type) {
                'mcq' => ['On the server'],
                'multi' => ['The database password', 'An API secret'],
                'truefalse' => ['False'],
                default => 'So a row cannot point at a record that is not there, and so the database says no rather '
                    .'than the application hoping.',
            };

            $marks = $written ? 0 : $question->marks;
            $scored += $marks;

            QuizAnswer::query()->create([
                'quiz_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'response' => $response,
                'is_correct' => ! $written,
                'marks_awarded' => $marks,
            ]);
        }

        $attempt->forceFill([
            'score' => $scored,
            'percent' => $total === 0 ? 0 : round($scored / $total * 100, 2),
            'passed' => false,
            // Left true on purpose: the written answer has not been read yet,
            // so the result is not final and the queue should say so.
            'needs_review' => true,
        ])->save();
    }

    protected function seedSubmission(Assignment $assignment, User $student): void
    {
        Submission::query()->updateOrCreate(
            ['assignment_id' => $assignment->id, 'user_id' => $student->id],
            [
                'notes' => 'The form validates on the server and the record saves. Given another week I would split '
                    .'the controller, which is doing two jobs at the moment.',
                'repository_url' => 'https://github.com/example/internship-project',
                'demo_url' => 'https://internship-project.example.test',
                'submitted_at' => now()->subDay(),
                'is_late' => false,
                'status' => 'submitted',
            ],
        );
    }

    protected function seedAnnouncements(Course $course, Batch $batch, ?User $trainer): void
    {
        $notices = [
            ['Session 5 moves to Thursday', 'Wednesday is a college holiday for most of you, so session five moves to '
                .'Thursday at the usual time. The link is unchanged.', true, 'batch'],
            ['Project briefs are up', 'The project brief is on the assignments page. Start it this week rather than '
                .'the night before — the mark is mostly for what you understood, which shows in the commits.', false, 'course'],
        ];

        foreach ($notices as [$title, $body, $pinned, $audience]) {
            Announcement::query()->updateOrCreate(
                ['title' => $title],
                [
                    'course_id' => $course->id,
                    'batch_id' => $audience === 'batch' ? $batch->id : null,
                    'author_id' => $trainer?->id,
                    'body' => $body,
                    'audience' => $audience,
                    'is_pinned' => $pinned,
                    'published_at' => now()->subDays($pinned ? 1 : 5),
                ],
            );
        }
    }

    protected function seedWarning(User $student, Batch $batch, ?User $trainer): void
    {
        StudentWarning::query()->updateOrCreate(
            ['user_id' => $student->id, 'batch_id' => $batch->id, 'level' => 'notice'],
            [
                'reason' => 'You have missed the last three classes and nothing has been handed in yet. Nothing has '
                    .'gone wrong that cannot be fixed from here, but tell us what is in the way so we can work '
                    .'around it.',
                'private_note' => 'Coordinator said there are examinations on. Worth a call before this escalates.',
                'issued_by' => $trainer?->id,
            ],
        );
    }

    protected function seedReview(User $student, Batch $batch, ?User $trainer): void
    {
        MentorReview::query()->updateOrCreate(
            ['user_id' => $student->id, 'batch_id' => $batch->id, 'week_number' => 4],
            [
                'reviewer_id' => $trainer?->id,
                'reviewed_on' => today()->subDays(2),
                'summary' => 'Reliable, asks good questions, and reads the error before asking. The project is ahead '
                    .'of where it needs to be at week four.',
                'what_went_well' => 'Debugged the validation problem alone rather than waiting for the next class.',
                'to_improve' => 'Commits are large. Smaller ones, with messages saying what changed and why.',
                'marks' => [
                    'technical' => 8,
                    'application' => 8,
                    'communication' => 7,
                    'initiative' => 9,
                    'punctuality' => 9,
                ],
            ],
        );
    }
}

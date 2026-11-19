<?php

use App\Events\Lms\ProctorUpdate;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizSlot;
use App\Models\User;
use App\Notifications\QuizAttemptEnded;
use App\Services\Lms\IntegrityChecks;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
 * Proctored quizzes (§9.1a): started in a sitting, agreed to, on a laptop or
 * computer, in Safe Exam Browser where the quiz says so; watched live; and the
 * proctor's three: a message, more time, the end. Lockdown only (X12).
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create(['name' => 'Meera Iyer']);

    $this->course = Course::query()->create([
        'title' => 'Databases', 'slug' => 'proctored-'.uniqid(), 'type' => 'course',
        'tagline' => 'Tables that hold', 'summary' => 'Six weeks.',
    ]);
    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id, 'name' => 'December batch', 'code' => 'PR-'.uniqid(), 'starts_on' => today()->subWeek(),
    ]);
    $this->otherBatch = Batch::query()->create([
        'course_id' => $this->course->id, 'name' => 'January batch', 'code' => 'PR-'.uniqid(), 'starts_on' => today()->addMonth(),
    ]);
    Enrollment::query()->create([
        'user_id' => $this->student->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeek(), 'has_paid' => true,
    ]);

    $this->quiz = Quiz::query()->create([
        'course_id' => $this->course->id, 'title' => 'Final assessment', 'time_limit_minutes' => 30,
        'attempts_allowed' => 2, 'pass_percent' => 50, 'shuffle_questions' => false, 'is_published' => true,
        'proctoring' => 'lockdown',
    ]);

    foreach ([['What does a primary key guarantee?', ['Speed', 'Uniqueness'], 'Uniqueness'], ['Which clause filters groups?', ['WHERE', 'HAVING'], 'HAVING']] as $index => [$body, $options, $right]) {
        Question::query()->create(['quiz_id' => $this->quiz->id, 'type' => 'mcq', 'body' => $body, 'options' => $options, 'correct' => [$right], 'marks' => 1, 'sort_order' => $index]);
    }

    $this->slot = QuizSlot::query()->create(['quiz_id' => $this->quiz->id, 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addMinutes(50)]);

    $this->start = fn (array $data = ['agree' => true], array $headers = []) => $this->actingAs($this->student)
        ->withHeaders($headers)
        ->post("/student/quizzes/{$this->quiz->id}/start", $data);
    $this->attempt = fn () => QuizAttempt::query()->where('user_id', $this->student->id)->latest('id')->first();
    $this->beat = fn (QuizAttempt $attempt, array $events = [], array $state = ['fullscreen' => true, 'focused' => true]) => $this->actingAs($this->student)
        ->postJson("/student/quizzes/{$this->quiz->id}/attempts/{$attempt->id}/events", ['events' => $events, 'state' => $state]);
    $this->proctor = function (string $permission = 'quiz.proctor'): User {
        $staff = User::factory()->admin()->create();
        $staff->permissions()->attach(Permission::query()->where('key', $permission)->sole());

        return $staff;
    };
});

/* ----------------------------------------------------------- starting */

it('tells the student when it can be sat, how it is watched, and what they agree to', function () {
    $this->actingAs($this->student)
        ->get("/student/quizzes/{$this->quiz->id}")
        ->assertInertia(fn ($page) => $page
            ->where('proctoring.mode', 'lockdown')
            ->where('proctoring.slot.open', true)
            ->where('proctoring.refusal', null)
            ->where('quiz.rules', fn ($rules) => collect($rules)->contains(fn ($rule) => str_contains($rule, 'This quiz is proctored'))
                && collect($rules)->contains(fn ($rule) => str_contains($rule, 'Nobody sees your screen or your camera, and nothing is recorded'))));
});

it('starts only once they have agreed, and only in a sitting for their batch', function () {
    ($this->start)([])->assertSessionHasErrors(['agree' => 'Tick that you have read how this quiz is proctored, to start.']);

    // A sitting for another batch is not theirs.
    $this->slot->update(['batch_id' => $this->otherBatch->id]);
    ($this->start)()->assertSessionHasErrors(['quiz' => 'No sitting is scheduled for your batch yet.']);

    // Theirs, but later.
    $this->slot->update(['batch_id' => $this->batch->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    ($this->start)()->assertSessionHasErrors('quiz');
    expect(session('errors')->first('quiz'))->toStartWith('It can be started during its sitting: ');

    expect(QuizAttempt::query()->count())->toBe(0);
});

it('is sat on a laptop or computer, never a phone or a tablet', function () {
    $phone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    ($this->start)(['agree' => true], ['User-Agent' => $phone])
        ->assertSessionHasErrors(['quiz' => 'This quiz is sat on a laptop or computer, not on a phone or tablet.']);
    ($this->start)(['agree' => true], ['Sec-CH-UA-Mobile' => '?1'])->assertSessionHasErrors('quiz');

    expect(QuizAttempt::query()->count())->toBe(0);
});

it('starts in the sitting, agreed to, and ends with the sitting at the latest', function () {
    $this->slot->update(['ends_at' => now()->addMinutes(20)]);

    ($this->start)()->assertRedirect();
    $attempt = ($this->attempt)();

    expect($attempt)
        ->quiz_slot_id->toBe($this->slot->id)
        ->proctoring->toBe('lockdown')
        ->consented_at->not->toBeNull()
        ->and($attempt->expires_at->timestamp)->toBe($this->slot->fresh()->ends_at->timestamp)
        ->and($attempt->events()->pluck('type')->all())->toBe(['started', 'consented']);
});

/* ------------------------------------------------- while it is running */

it('hears the page every few seconds and tells the proctor what changed', function () {
    ($this->start)();
    $attempt = ($this->attempt)();
    Event::fake([ProctorUpdate::class]);

    ($this->beat)($attempt, [['type' => 'focus_lost', 'why' => 'fullscreen'], ['type' => 'fullscreen_exit']], ['fullscreen' => false, 'focused' => true])
        ->assertOk()
        ->assertJson(['ended' => false, 'exits' => 1, 'messages' => []]);

    expect($attempt->fresh()->live_state)->toBe(['fullscreen' => false, 'focused' => true])
        ->and($attempt->events()->where('type', 'focus_lost')->sole()->detail)->toBe(['why' => 'fullscreen']);

    Event::assertDispatched(ProctorUpdate::class, fn (ProctorUpdate $event) => $event->quizId === $this->quiz->id
        && $event->tile['student'] === 'Meera Iyer'
        && $event->tile['exits'] === 1
        && $event->tile['fullscreen'] === false
        && $event->broadcastOn()->name === 'private-quiz-proctor.'.$this->quiz->id);

    // A beat with nothing new tells nobody anything.
    Event::fake([ProctorUpdate::class]);
    ($this->beat)($attempt, [], ['fullscreen' => false, 'focused' => true])->assertOk();
    Event::assertNotDispatched(ProctorUpdate::class);
});

it('keeps only what a proctored page may report about itself', function () {
    ($this->start)();
    $attempt = ($this->attempt)();

    ($this->beat)($attempt, [
        ['type' => 'blocked', 'what' => 'paste'],
        ['type' => 'blocked', 'what' => 'shortcut', 'key' => 'Ctrl+Shift+I'],
        ['type' => 'blocked', 'what' => 'something else'],
        ['type' => 'blocked', 'what' => 'shortcut', 'key' => '<script>'],
        ['type' => 'message_seen', 'message' => 999999],
    ])->assertJson(['kept' => 3]);

    expect($attempt->events()->where('type', 'blocked')->pluck('detail')->all())->toBe([
        ['what' => 'paste'],
        ['what' => 'shortcut', 'key' => 'Ctrl+Shift+I'],
        ['what' => 'shortcut'],
    ]);
});

it('counts one departure once, though the closing page and the opening one both report it', function () {
    ($this->start)();
    $attempt = ($this->attempt)();
    $out = ['fullscreen' => false, 'focused' => true];

    // A reload: the page going reports leaving full screen, and so does the page coming back.
    ($this->beat)($attempt, [['type' => 'focus_lost', 'why' => 'fullscreen']], $out)->assertJson(['exits' => 1]);
    ($this->beat)($attempt, [['type' => 'focus_lost', 'why' => 'fullscreen']], $out)->assertJson(['exits' => 1]);

    // Back, then away again: that is a second time.
    ($this->beat)($attempt, [['type' => 'focus_back', 'seconds' => 12]])->assertJson(['exits' => 1]);
    ($this->beat)($attempt, [['type' => 'focus_lost', 'why' => 'tab']], ['fullscreen' => true, 'focused' => false])->assertJson(['exits' => 2]);

    // Back without the page managing to say so, as far as the record goes: its
    // state says it is in full screen and in focus, so the next time counts.
    ($this->beat)($attempt, [], ['fullscreen' => true, 'focused' => true]);
    ($this->beat)($attempt, [['type' => 'focus_lost', 'why' => 'window']], $out)->assertJson(['exits' => 3]);
});

it('submits it for them once they have left as often as the quiz allows', function () {
    $this->quiz->update(['max_exits' => 2]);
    ($this->start)();
    $attempt = ($this->attempt)();

    ($this->beat)($attempt, [['type' => 'focus_lost', 'why' => 'tab']])->assertJson(['ended' => false, 'exits' => 1]);
    ($this->beat)($attempt, [['type' => 'focus_back', 'seconds' => 4], ['type' => 'focus_lost', 'why' => 'window']])
        ->assertJson(['ended' => true, 'review' => "/student/quizzes/{$this->quiz->id}/attempts/{$attempt->id}/review"]);

    expect($attempt->fresh())
        ->submitted_at->not->toBeNull()
        ->ended_by->toBeNull()
        ->ended_reason->toBe('Left the quiz 2 times, which this quiz allows 2 times. It was submitted with what had been answered.')
        ->and($attempt->events()->where('type', 'ended_for_exits')->exists())->toBeTrue();
});

/* ------------------------------------------------- the proctor's three */

it('lets a proctor message a student, who reads it over the quiz', function () {
    ($this->start)();
    $attempt = ($this->attempt)();
    $proctor = ($this->proctor)();

    $this->actingAs($proctor)
        ->post("/admin/proctoring/attempts/{$attempt->id}/message", ['text' => 'Please keep the quiz in full screen.'])
        ->assertSessionHas('success');

    $messages = ($this->beat)($attempt)->json('messages');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toBe('Please keep the quiz in full screen.')
        ->and(AuditLog::query()->where('action', 'quiz.proctor_message')->sole()->actor_id)->toBe($proctor->id);

    // Read, and gone.
    ($this->beat)($attempt, [['type' => 'message_seen', 'message' => $messages[0]['id']]])->assertJson(['messages' => []]);
});

it('gives more time, from where the clock stands, with a reason in the audit log', function () {
    ($this->start)();
    $attempt = ($this->attempt)();
    $before = $attempt->expires_at->copy();

    $this->actingAs($this->owner)
        ->post("/admin/proctoring/attempts/{$attempt->id}/time", ['minutes' => 10, 'reason' => 'The connection dropped at 10:20.'])
        ->assertSessionHas('success', '10 minutes added.');

    expect($attempt->fresh()->expires_at->timestamp)->toBe($before->addMinutes(10)->timestamp)
        ->and(AuditLog::query()->where('action', 'quiz.time_added')->sole()->changes['reason'])->toBe('The connection dropped at 10:20.')
        ->and(($this->beat)($attempt)->json('secondsRemaining'))->toBeGreaterThan(39 * 60);

    // Never more than an hour at a time.
    $this->actingAs($this->owner)
        ->post("/admin/proctoring/attempts/{$attempt->id}/time", ['minutes' => 90, 'reason' => 'Too much'])
        ->assertSessionHasErrors('minutes');
});

it('ends an attempt with a reason, marks what was answered and tells the student why', function () {
    Notification::fake();
    ($this->start)();
    $attempt = ($this->attempt)();
    $first = $this->quiz->questions()->first();

    $this->actingAs($this->student)->postJson("/student/quizzes/{$this->quiz->id}/attempts/{$attempt->id}/answers", ['question_id' => $first->id, 'response' => 'Uniqueness'])->assertOk();

    $this->actingAs($this->owner)
        ->post("/admin/proctoring/attempts/{$attempt->id}/end", ['reason' => 'A second person was helping.'])
        ->assertSessionHas('success');

    expect($attempt->fresh())
        ->submitted_at->not->toBeNull()
        ->ended_by->toBe($this->owner->id)
        ->ended_reason->toBe('A second person was helping.')
        ->score->toBe(1);

    Notification::assertSentTo($this->student, QuizAttemptEnded::class, fn (QuizAttemptEnded $notice) => $notice->toArray($this->student)['url'] === "/student/quizzes/{$this->quiz->id}/attempts/{$attempt->id}/review");

    ($this->beat)($attempt)->assertJson(['ended' => true]);

    $this->actingAs($this->student)
        ->get("/student/quizzes/{$this->quiz->id}/attempts/{$attempt->id}/review")
        ->assertInertia(fn ($page) => $page->where('attempt.endedReason', 'A second person was helping.')->where('attempt.endedByProctor', true));

    // Once it has finished, none of the three does anything.
    $this->actingAs($this->owner)
        ->post("/admin/proctoring/attempts/{$attempt->id}/time", ['minutes' => 5, 'reason' => 'Late'])
        ->assertSessionHasErrors(['attempt' => 'That attempt has already finished.']);
});

/* ------------------------------------------------------ the console */

it('shows a proctor everybody sitting it, and who has not started', function () {
    $friend = User::factory()->student()->create(['name' => 'Zoya Khan']);
    Enrollment::query()->create(['user_id' => $friend->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id, 'enrolled_at' => now(), 'has_paid' => true]);

    ($this->start)();
    $attempt = ($this->attempt)();
    ($this->beat)($attempt, [['type' => 'blocked', 'what' => 'copy']], ['fullscreen' => true, 'focused' => false]);

    $proctor = ($this->proctor)();

    $this->actingAs($proctor)
        ->get("/admin/proctoring/quizzes/{$this->quiz->id}/console")
        ->assertInertia(fn ($page) => $page
            ->component('admin/proctoring/Console')
            ->where('sitting.open', true)
            ->where('tiles.0.student', 'Meera Iyer')
            ->where('tiles.0.blocked', 1)
            ->where('tiles.0.focused', false)
            ->where('tiles.0.status', 'here')
            ->where('waiting', ['Zoya Khan']));

    $this->actingAs($proctor)
        ->getJson("/admin/proctoring/quizzes/{$this->quiz->id}/console/status")
        ->assertOk()
        ->assertJsonPath('tiles.0.total', 2);

    $this->actingAs($proctor)->get('/admin/proctoring')->assertOk();
});

it('keeps the console and its channel to those who may proctor', function () {
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb', [
        'driver' => 'reverb', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test-app',
        'options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
    ]);
    require base_path('routes/channels.php');

    ($this->start)();
    $attempt = ($this->attempt)();
    $builder = ($this->proctor)('catalogue.manage');
    $proctor = ($this->proctor)();

    $this->actingAs($builder)->get("/admin/proctoring/quizzes/{$this->quiz->id}/console")->assertForbidden();
    $this->actingAs($builder)->post("/admin/proctoring/attempts/{$attempt->id}/end", ['reason' => 'x'])->assertForbidden();
    $this->actingAs($this->student)->get("/admin/proctoring/quizzes/{$this->quiz->id}/console")->assertRedirect();

    $channel = fn (User $user) => $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-quiz-proctor.'.$this->quiz->id]);

    $channel($proctor)->assertOk();
    $channel($builder)->assertForbidden();
    $channel($this->student)->assertForbidden();

    // Setting it up is the course builder's, not the proctor's.
    $this->actingAs($proctor)->get("/admin/proctoring/quizzes/{$this->quiz->id}")->assertForbidden();
    $this->actingAs($builder)->get("/admin/proctoring/quizzes/{$this->quiz->id}")->assertOk();
})->after(fn () => config()->set('broadcasting.default', 'null'));

/* ------------------------------------------------------- setting it up */

it('saves how a quiz is watched, and waits for a relay before the camera', function () {
    $this->actingAs($this->owner)
        ->put("/admin/proctoring/quizzes/{$this->quiz->id}", ['proctoring' => 'live'])
        ->assertSessionHasErrors(['proctoring' => 'The camera joins once a video relay is set up. Choose lockdown for now.']);

    $this->actingAs($this->owner)
        ->put("/admin/proctoring/quizzes/{$this->quiz->id}", ['proctoring' => 'lockdown', 'require_seb' => true])
        ->assertSessionHasErrors(['seb_config_keys' => 'Add the configuration key from Safe Exam Browser\'s settings, or nobody could start it.']);

    $this->actingAs($this->owner)
        ->put("/admin/proctoring/quizzes/{$this->quiz->id}", ['proctoring' => 'lockdown', 'require_seb' => true, 'seb_config_keys' => 'not-a-key'])
        ->assertSessionHasErrors('seb_config_keys');

    $this->actingAs($this->owner)
        ->put("/admin/proctoring/quizzes/{$this->quiz->id}", ['proctoring' => 'off', 'require_seb' => true, 'seb_config_keys' => str_repeat('a', 64)])
        ->assertSessionHasErrors('require_seb');

    $key = strtoupper(str_repeat('ab', 32));

    $this->actingAs($this->owner)
        ->put("/admin/proctoring/quizzes/{$this->quiz->id}", ['proctoring' => 'lockdown', 'max_exits' => 3, 'require_seb' => true, 'seb_config_keys' => " {$key}\n{$key} "])
        ->assertSessionHasNoErrors();

    expect($this->quiz->fresh())
        ->max_exits->toBe(3)
        ->require_seb->toBeTrue()
        ->seb_config_keys->toBe([strtolower($key)])
        ->and(AuditLog::query()->where('action', 'updated')->where('subject_type', Quiz::class)->exists())->toBeTrue();
});

it('adds sittings that make sense, and keeps one somebody started in', function () {
    $url = "/admin/proctoring/quizzes/{$this->quiz->id}/slots";
    $at = fn (string $when) => now()->modify($when)->format('Y-m-d\TH:i');

    $this->actingAs($this->owner)->post($url, ['starts_at' => $at('+2 hours'), 'ends_at' => $at('+1 hour')])
        ->assertSessionHasErrors(['ends_at' => 'A sitting ends after it starts.']);
    $this->actingAs($this->owner)->post($url, ['starts_at' => $at('-3 hours'), 'ends_at' => $at('-2 hours')])
        ->assertSessionHasErrors(['ends_at' => 'That sitting would already be over.']);
    $this->actingAs($this->owner)->post($url, ['starts_at' => $at('+1 day'), 'ends_at' => $at('+1 day +13 hours')])
        ->assertSessionHasErrors('ends_at');
    $this->actingAs($this->owner)->post($url, ['starts_at' => $at('+1 day'), 'ends_at' => $at('+1 day +20 minutes')])
        ->assertSessionHasErrors(['ends_at' => "Shorter than the quiz's 30 minutes, so it would cut everybody short."]);

    $elsewhere = Batch::query()->create(['course_id' => Course::query()->create(['title' => 'Other', 'slug' => 'other-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'y'])->id, 'name' => 'Elsewhere', 'code' => 'EL-'.uniqid(), 'starts_on' => today()]);
    $this->actingAs($this->owner)->post($url, ['starts_at' => $at('+1 day'), 'ends_at' => $at('+1 day +1 hour'), 'batch_id' => $elsewhere->id])
        ->assertSessionHasErrors('batch_id');

    $this->actingAs($this->owner)->post($url, ['starts_at' => $at('+1 day'), 'ends_at' => $at('+1 day +1 hour'), 'batch_id' => $this->batch->id, 'note' => 'Room 4'])
        ->assertSessionHas('success', 'Sitting added.');

    $added = QuizSlot::query()->latest('id')->first();
    expect($added)->batch_id->toBe($this->batch->id)->note->toBe('Room 4')->created_by->toBe($this->owner->id);

    $this->actingAs($this->owner)->delete("/admin/proctoring/slots/{$added->id}")->assertSessionHas('success', 'Sitting removed.');
    expect(QuizSlot::query()->find($added->id))->toBeNull();

    ($this->start)();
    $this->actingAs($this->owner)->delete("/admin/proctoring/slots/{$this->slot->id}")
        ->assertSessionHasErrors(['slot' => 'Students have started in this sitting, so it stays on the record.']);
});

/* --------------------------------------------- Safe Exam Browser (X10) */

it('refuses an ordinary browser where the quiz requires Safe Exam Browser, request by request', function () {
    $key = str_repeat('c0', 32);
    $this->quiz->update(['require_seb' => true, 'seb_config_keys' => [$key]]);
    // Over the full address of each request, as Safe Exam Browser sends it.
    $sign = fn (string $path) => ['X-SafeExamBrowser-ConfigKeyHash' => hash('sha256', url($path).$key)];
    $startPath = "/student/quizzes/{$this->quiz->id}/start";

    ($this->start)()->assertSessionHasErrors(['quiz' => 'This quiz is taken in Safe Exam Browser. Open it from there.']);

    // A header made for another address does not fit this one.
    ($this->start)(['agree' => true], $sign('/somewhere-else'))->assertSessionHasErrors('quiz');

    ($this->start)(['agree' => true], $sign($startPath))->assertSessionHasNoErrors();
    $attempt = ($this->attempt)();
    $attemptPath = "/student/quizzes/{$this->quiz->id}/attempts/{$attempt->id}";

    $this->actingAs($this->student)->get($attemptPath)
        ->assertRedirect("/student/quizzes/{$this->quiz->id}")
        ->assertSessionHas('error', 'This quiz is taken in Safe Exam Browser. Open it from there.');

    $this->actingAs($this->student)->withHeaders($sign($attemptPath))->get($attemptPath)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('proctoring.inSeb', true)->has('questions', 2));

    $this->actingAs($this->student)
        ->postJson("{$attemptPath}/answers", ['question_id' => $this->quiz->questions()->value('id'), 'response' => 'Speed'])
        ->assertForbidden();
});

it('hands Safe Exam Browser its settings file by a signed link, and nobody else', function () {
    Storage::fake('local');
    $this->quiz->update(['require_seb' => true, 'seb_config_keys' => [str_repeat('d', 64)]]);

    $this->actingAs($this->owner)
        ->post("/admin/proctoring/quizzes/{$this->quiz->id}/safe-exam-browser", ['file' => UploadedFile::fake()->createWithContent('final.txt', 'not it')])
        ->assertSessionHasErrors('file');

    $this->actingAs($this->owner)
        ->post("/admin/proctoring/quizzes/{$this->quiz->id}/safe-exam-browser", ['file' => UploadedFile::fake()->createWithContent('final.seb', '<plist>settings</plist>')])
        ->assertSessionHas('success');

    $launch = $this->actingAs($this->student)->get("/student/quizzes/{$this->quiz->id}")->viewData('page')['props']['proctoring']['sebLaunchUrl'];

    expect($launch)->toStartWith(preg_replace('#^http#', 'seb', url("/student/quizzes/{$this->quiz->id}/safe-exam-browser?")));

    auth()->logout();

    $this->get(preg_replace('#^seb://#', 'http://', $launch))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/seb');
    $this->get("/student/quizzes/{$this->quiz->id}/safe-exam-browser")->assertForbidden();
    $this->get(URL::temporarySignedRoute('quizzes.safe-exam-browser', now()->subMinute(), ['quiz' => $this->quiz->id]))->assertForbidden();
});

/* ------------------------------------------------------- what follows */

it('flags a proctored attempt that left or tried things, and one the proctor ended', function () {
    ($this->start)();
    $attempt = ($this->attempt)();
    ($this->beat)($attempt, [['type' => 'focus_lost', 'why' => 'tab'], ['type' => 'blocked', 'what' => 'copy'], ['type' => 'blocked', 'what' => 'copy']]);

    $this->actingAs($this->owner)->post("/admin/proctoring/attempts/{$attempt->id}/end", ['reason' => 'Talking to somebody off screen.']);

    app(IntegrityChecks::class)->run($this->quiz);
    $flags = collect($attempt->fresh()->flags)->keyBy('type');

    expect($flags['lockdown']['summary'])->toBe('Left the quiz 1 time and tried something switched off 2 times during a proctored sitting.')
        ->and($flags['lockdown']['evidence']['blocked'])->toBe(['copy' => 2])
        ->and($flags['ended']['summary'])->toBe('Ended by the proctor: Talking to somebody off screen.');
});

it('leaves a quiz that is not proctored exactly as it was', function () {
    $this->quiz->update(['proctoring' => 'off']);

    $this->actingAs($this->student)->post("/student/quizzes/{$this->quiz->id}/start")->assertRedirect();

    expect(($this->attempt)())->proctoring->toBeNull()->quiz_slot_id->toBeNull();

    $this->actingAs($this->student)
        ->get("/student/quizzes/{$this->quiz->id}")
        ->assertInertia(fn ($page) => $page->where('proctoring', null));
});

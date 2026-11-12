<?php

namespace App\Services\Legacy;

use App\Models\Attendance;
use App\Models\Certificate;
use App\Models\QuizAttempt;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Legacy\Steps\PaymentsStep;
use App\Services\Legacy\Steps\UsersStep;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Source against target, after an import.
 *
 * The strict checks are the ones somebody would notice: people, enrolments,
 * money to the paisa, certificates and their QR links, attendance and quiz
 * scores. Any difference there fails the run. Counts for everything else are
 * shown for information, with the source ids that did not come across, to
 * be read against the import report's warnings. Nothing here names a person.
 */
class Reconciler
{
    /** @var array<int, array{check: string, source: int|string, target: int|string, ok: bool, strict: bool, ids: array<int, int|string>}> */
    protected array $checks = [];

    /** @return array<int, array<string, mixed>> */
    public function run(): array
    {
        $this->checks = [];

        $this->users();
        $this->enrolments();
        $this->payments();
        $this->certificates();
        $this->attendance();
        $this->quizzes();

        foreach (['training_programs', 'batches', 'live_classes', 'study_materials', 'notices', 'assignments', 'support_tickets', 'reviews', 'contact_queries', 'team_members', 'custom_pages'] as $table) {
            $this->counts($table);
        }

        return $this->checks;
    }

    public function passed(): bool
    {
        return collect($this->checks)->every(fn (array $check) => $check['ok'] || ! $check['strict']);
    }

    protected function users(): void
    {
        if (! $this->has('users')) {
            return;
        }

        $expected = $this->source('users')->get(['id', 'email', 'role'])
            ->filter(fn ($row) => isset(UsersStep::ROLES[strtolower((string) $row->role)]) && filter_var(Str::lower(trim($row->email)), FILTER_VALIDATE_EMAIL));

        $missing = $expected->filter(fn ($row) => User::query()->withTrashed()->where('email', Str::lower(trim($row->email)))->count() !== 1);

        $this->add('Every v2 account is in v3 exactly once, by email', $expected->count(), $expected->count() - $missing->count(), $missing->pluck('id'), strict: true);
    }

    protected function enrolments(): void
    {
        if (! $this->has('enrollments')) {
            return;
        }

        // A v2 enrolment still waiting for payment is deliberately not one in v3.
        $expected = $this->source('enrollments')->where('status', '!=', 'pending_payment')->pluck('id');
        $missing = $expected->filter(fn ($id) => ! $this->mapped('enrollments', $id));

        $this->add('Every v2 enrolment that was not waiting for payment is in v3', $expected->count(), $expected->count() - $missing->count(), $missing, strict: true);
    }

    protected function payments(): void
    {
        if (! $this->has('payments')) {
            return;
        }

        $rows = $this->source('payments')->get();
        $paid = $rows->filter(fn ($row) => (PaymentsStep::STATUSES[strtolower((string) $row->status)] ?? null) === 'successful');
        $missing = $rows->filter(fn ($row) => ! $this->mapped('payments', $row->id))->pluck('id');

        $this->add('Every v2 payment is a v3 transaction', $rows->count(), $rows->count() - $missing->count(), $missing, strict: true);

        $imported = Transaction::query()->whereIn('id', $this->targets('payments'))->where('status', 'successful')->get();
        $sourceTotal = $paid->sum(fn ($row) => Money::toPaise($row->amount));
        $targetTotal = (int) $imported->sum('amount');

        $this->add('Paid in total, in paise', $sourceTotal, $targetTotal, [], strict: true);

        // Per student and per month: a total can balance while two people's do not.
        $byStudent = $paid->groupBy('student_id')->map(fn ($group) => $group->sum(fn ($row) => Money::toPaise($row->amount)));
        $wrong = $byStudent->filter(function (int $paise, $studentId) use ($imported) {
            $userId = $this->target('users', $studentId);

            return $userId === null || (int) $imported->where('user_id', $userId)->sum('amount') !== $paise;
        })->keys();

        $this->add('Paid per student, in paise', $byStudent->count(), $byStudent->count() - $wrong->count(), $wrong->map(fn ($id) => "users#{$id}"), strict: true);

        $sourceMonths = $paid->groupBy(fn ($row) => substr((string) ($row->updated_at ?? $row->created_at), 0, 7))->map(fn ($g) => $g->sum(fn ($row) => Money::toPaise($row->amount)));
        $targetMonths = $imported->groupBy(fn ($t) => $t->paid_at?->format('Y-m'))->map(fn ($g) => (int) $g->sum('amount'));
        $months = $sourceMonths->keys()->filter(fn ($month) => ($targetMonths[$month] ?? 0) !== $sourceMonths[$month]);

        $this->add('Paid per month, in paise', $sourceMonths->count(), $sourceMonths->count() - $months->count(), $months->values(), strict: true);

        $ids = $rows->pluck('razorpay_payment_id')->filter()->unique();
        $notOnce = $ids->filter(fn ($id) => Transaction::query()->where('gateway_payment_id', $id)->count() !== 1);

        $this->add('Every Razorpay payment id appears exactly once', $ids->count(), $ids->count() - $notOnce->count(), $notOnce->values(), strict: true);
    }

    protected function certificates(): void
    {
        if (! $this->has('certificates')) {
            return;
        }

        $rows = $this->source('certificates')->get();

        $missing = $rows->filter(fn ($row) => ! Certificate::query()->where('number', $row->certificate_number)->exists())->pluck('id');
        $this->add('Every certificate number is in v3', $rows->count(), $rows->count() - $missing->count(), $missing, strict: true);

        $tokens = $rows->filter(function ($row) {
            $certificate = Certificate::query()->where('legacy_token', $row->token)->first();

            return ! $certificate || ! hash_equals((string) $certificate->legacy_token, (string) $row->token) || $certificate->number !== $row->certificate_number;
        })->pluck('id');
        $this->add('Every old QR link verifies, to the right certificate', $rows->count(), $rows->count() - $tokens->count(), $tokens, strict: true);

        $revoked = $rows->filter(fn ($row) => (bool) $row->revoked_at !== (bool) Certificate::query()->where('number', $row->certificate_number)->value('revoked_at'))->pluck('id');
        $this->add('Revoked certificates are still revoked, and only those', $rows->count(), $rows->count() - $revoked->count(), $revoked, strict: true);

        $withPdf = Certificate::query()->whereIn('id', $this->targets('certificates'))->whereNotNull('pdf_path')->count();
        $this->add('Certificates with the old site\'s PDF (the rest are drawn by v3)', $rows->count(), $withPdf, [], strict: false);
    }

    protected function attendance(): void
    {
        if (! $this->has('attendances')) {
            return;
        }

        $bySession = $this->source('attendances')->get(['live_class_id'])->countBy('live_class_id');
        $wrong = $bySession->filter(function (int $count, $classId) {
            $sessionId = $this->target('live_classes', $classId);

            return $sessionId === null || Attendance::query()->where('live_session_id', $sessionId)->where('status', 'present')->count() !== $count;
        })->keys();

        $this->add('Present per class', $bySession->count(), $bySession->count() - $wrong->count(), $wrong->map(fn ($id) => "live_classes#{$id}"), strict: true);
    }

    protected function quizzes(): void
    {
        if (! $this->has('quiz_attempts')) {
            return;
        }

        $byQuiz = $this->source('quiz_attempts')->get()->groupBy('quiz_id');
        $wrong = $byQuiz->filter(function ($attempts, $quizId) {
            $target = $this->target('quizzes', $quizId);

            if ($target === null) {
                return true;
            }

            $imported = QuizAttempt::query()->where('quiz_id', $target)->get();

            return $imported->count() !== $attempts->count()
                || (int) $imported->sum('score') !== (int) $attempts->sum(fn ($a) => (int) round((float) $a->score));
        })->keys();

        $this->add('Quiz attempts and their scores, per quiz', $byQuiz->count(), $byQuiz->count() - $wrong->count(), $wrong->map(fn ($id) => "quizzes#{$id}"), strict: true);
    }

    protected function counts(string $table): void
    {
        if (! $this->has($table)) {
            return;
        }

        $ids = $this->source($table)->pluck('id');
        $missing = $ids->filter(fn ($id) => ! $this->mapped($table, $id));

        $this->add("{$table}: imported", $ids->count(), $ids->count() - $missing->count(), $missing, strict: false);
    }

    /* ------------------------------------------------------------------ */

    protected function add(string $check, int|string $source, int|string $target, $ids, bool $strict): void
    {
        $ids = collect($ids)->values();

        $this->checks[] = [
            'check' => $check,
            'source' => $source,
            'target' => $target,
            'ok' => $source === $target && $ids->isEmpty(),
            'strict' => $strict,
            // The first fifty source ids to look at, never a name or an email.
            'ids' => $ids->take(50)->all(),
        ];
    }

    protected function has(string $table): bool
    {
        return Schema::connection('legacy')->hasTable($table);
    }

    protected function source(string $table)
    {
        return DB::connection('legacy')->table($table);
    }

    protected function mapped(string $table, int|string $id): bool
    {
        return DB::table('legacy_id_map')->where('source_table', $table)->where('source_id', $id)->exists();
    }

    protected function target(string $table, int|string $id): ?int
    {
        return DB::table('legacy_id_map')->where('source_table', $table)->where('source_id', $id)->value('target_id');
    }

    /** @return array<int, int> */
    protected function targets(string $table): array
    {
        return DB::table('legacy_id_map')->where('source_table', $table)->pluck('target_id')->all();
    }
}

<?php

namespace App\Services\Legacy;

use App\Services\Legacy\Steps\AssignmentsStep;
use App\Services\Legacy\Steps\AttendanceStep;
use App\Services\Legacy\Steps\BatchesStep;
use App\Services\Legacy\Steps\CertificatesStep;
use App\Services\Legacy\Steps\CmsStep;
use App\Services\Legacy\Steps\CoursesStep;
use App\Services\Legacy\Steps\EnrolmentsStep;
use App\Services\Legacy\Steps\LeadsStep;
use App\Services\Legacy\Steps\MaterialsStep;
use App\Services\Legacy\Steps\NoticesStep;
use App\Services\Legacy\Steps\PaymentsStep;
use App\Services\Legacy\Steps\QuizzesStep;
use App\Services\Legacy\Steps\ReviewsStep;
use App\Services\Legacy\Steps\SessionsStep;
use App\Services\Legacy\Steps\Step;
use App\Services\Legacy\Steps\SupportStep;
use App\Services\Legacy\Steps\UsersStep;
use App\Services\Lms\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Carries the ubs-v2 data into v3, step by step, in dependency order.
 *
 * Nothing leaves the building while it runs: notifications, mail and queued
 * jobs are faked and broadcasting is switched off, so importing a hundred
 * students sends nobody a welcome and awards nobody points. A dry run does
 * all of it inside a transaction and rolls back, so its report is exactly
 * what a real run would do.
 */
class Importer
{
    /** @var array<int, class-string<Step>> in the order they must run */
    public const STEPS = [
        UsersStep::class,
        CoursesStep::class,
        BatchesStep::class,
        EnrolmentsStep::class,
        SessionsStep::class,
        AttendanceStep::class,
        MaterialsStep::class,
        NoticesStep::class,
        QuizzesStep::class,
        AssignmentsStep::class,
        CertificatesStep::class,
        PaymentsStep::class,
        SupportStep::class,
        ReviewsStep::class,
        LeadsStep::class,
        CmsStep::class,
    ];

    /** @return array<int, string> */
    public static function stepNames(): array
    {
        return array_map(fn (string $class) => (new $class)->name(), self::STEPS);
    }

    /** @param  array<int, string>  $only  step names; empty runs them all */
    public function run(Context $context, array $only = []): Report
    {
        $unknown = array_diff($only, self::stepNames());

        if ($unknown) {
            throw new InvalidArgumentException('No such step: '.implode(', ', $unknown).'. Steps are: '.implode(', ', self::stepNames()).'.');
        }

        $this->silence();

        // A dry run is one transaction rolled back at the very end, so each
        // step sees what the steps before it would have made. A real run
        // commits step by step, so a failure keeps what already succeeded
        // and a re-run carries on from there.
        if ($context->report->dryRun) {
            DB::beginTransaction();

            try {
                $this->steps($context, $only);
            } finally {
                DB::rollBack();
            }

            return $context->report;
        }

        foreach ($this->selected($only) as $step) {
            DB::transaction(fn () => $this->runStep($step, $context));
        }

        return $context->report;
    }

    protected function steps(Context $context, array $only): void
    {
        foreach ($this->selected($only) as $step) {
            $this->runStep($step, $context);
        }
    }

    /** @return array<int, Step> */
    protected function selected(array $only): array
    {
        return collect(self::STEPS)
            ->map(fn (string $class) => new $class)
            ->filter(fn (Step $step) => ! $only || in_array($step->name(), $only, true))
            ->values()
            ->all();
    }

    protected function runStep(Step $step, Context $context): void
    {
        // A dump missing a table is reported, not guessed around.
        $missing = array_filter($step->sourceTables(), fn (string $table) => ! Schema::connection('legacy')->hasTable($table));

        if ($missing) {
            $context->report->count($step->name(), 'skipped', 0);
            $context->report->warn($step->name(), 'source_missing', implode(', ', $missing), 'The dump has no such table, so this step did not run.');

            return;
        }

        $step->run($context);
    }

    protected function silence(): void
    {
        Notification::fake();
        Mail::fake();
        Queue::fake();
        config(['broadcasting.default' => 'null']);
        Activity::mute();
    }
}

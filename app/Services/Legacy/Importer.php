<?php

namespace App\Services\Legacy;

use App\Services\Legacy\Steps\Step;
use App\Services\Legacy\Steps\UsersStep;
use App\Services\Lms\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Throwable;

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

        foreach (self::STEPS as $class) {
            $step = new $class;

            if ($only && ! in_array($step->name(), $only, true)) {
                continue;
            }

            DB::beginTransaction();

            try {
                $step->run($context);
                $context->report->dryRun ? DB::rollBack() : DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();

                throw $e;
            }
        }

        return $context->report;
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

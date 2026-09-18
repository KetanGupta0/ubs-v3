<?php

namespace App\Console\Commands;

use App\Mail\ScheduledReport;
use App\Models\ReportSchedule;
use App\Services\Reports\ReportExport;
use App\Services\Reports\ReportLibrary;
use App\Services\Reports\ReportWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Send the reports somebody asked to have sent.
 *
 * Run hourly rather than at one fixed time, because a schedule set for seven
 * in the morning should arrive at seven. Each one decides for itself whether
 * it is due, checked against when it last went out rather than against the
 * clock alone: a worker that runs twice in an hour must not send twice, and
 * one that was down all morning should still send once when it comes back.
 *
 * A failure is recorded on the schedule rather than swallowed. Nobody notices
 * a report that quietly stopped arriving until they need it.
 */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send {--force : Send every active schedule, due or not}';

    protected $description = 'Email the reports that are due';

    public function handle(ReportLibrary $library, ReportExport $exporter): int
    {
        $sent = 0;

        ReportSchedule::query()
            ->active()
            ->with('user')
            ->get()
            ->filter(fn (ReportSchedule $schedule) => $this->option('force') || $schedule->isDue())
            ->each(function (ReportSchedule $schedule) use ($library, $exporter, &$sent) {
                try {
                    $this->send($schedule, $library, $exporter);
                    $sent++;
                } catch (Throwable $e) {
                    report($e);

                    $schedule->forceFill([
                        'last_failed_at' => now(),
                        'last_error' => str($e->getMessage())->limit(180)->toString(),
                    ])->save();

                    $this->warn("Could not send {$schedule->report}: {$e->getMessage()}");
                }
            });

        $this->info($sent === 0 ? 'Nothing due.' : "Sent {$sent}.");

        return self::SUCCESS;
    }

    protected function send(ReportSchedule $schedule, ReportLibrary $library, ReportExport $exporter): void
    {
        $report = $library->find($schedule->report);

        if (! $report) {
            throw new \RuntimeException("There is no report called {$schedule->report}.");
        }

        /*
         * The person who set the schedule has to still be allowed to see it.
         * Permissions change, and a monthly email is exactly the thing nobody
         * remembers to cancel when somebody moves off the accounts team.
         */
        if ($report->permission() && ! $schedule->user?->hasPermission($report->permission())) {
            throw new \RuntimeException('The person who scheduled this no longer has access to it.');
        }

        $window = $this->windowFor($schedule);
        $payload = $report->run($window);

        // Rendered to a real file so it can be attached, then removed: a
        // reports directory that quietly fills with every month's PDF is a disk
        // that fills too.
        $response = $exporter->download($report, $window, $schedule->format);

        $path = storage_path('app/'.$this->fileName($schedule, $window));

        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $this->contentOf($response));

        try {
            Mail::to($schedule->recipients)->send(new ScheduledReport(
                $schedule,
                $payload,
                $path,
                basename($path),
            ));

            $schedule->forceFill([
                'last_sent_at' => now(),
                'last_failed_at' => null,
                'last_error' => null,
            ])->save();
        } finally {
            @unlink($path);
        }
    }

    /** The period the cadence implies: yesterday, last week, last month. */
    protected function windowFor(ReportSchedule $schedule): ReportWindow
    {
        [$from, $to] = match ($schedule->cadence) {
            'daily' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'weekly' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            default => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
        };

        return new ReportWindow($from, $to, $schedule->filters ?? []);
    }

    protected function fileName(ReportSchedule $schedule, ReportWindow $window): string
    {
        return sprintf(
            'reports/%s-%s.%s',
            $schedule->report,
            $window->to->format('Y-m-d'),
            $schedule->format,
        );
    }

    /** Streamed responses have to be run to get at what they wrote. */
    protected function contentOf($response): string
    {
        if (method_exists($response, 'sendContent')) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return (string) $response->getContent();
    }
}

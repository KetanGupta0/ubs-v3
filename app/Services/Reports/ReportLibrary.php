<?php

namespace App\Services\Reports;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Every report, in one register.
 *
 * A list rather than a folder scan, so adding a report is a deliberate act and
 * the order on screen is a decision somebody made rather than whatever the
 * filesystem returned.
 */
class ReportLibrary
{
    /** @var array<int, class-string<Report>> */
    public const REPORTS = [
        RevenueReport::class,
        ReceivablesReport::class,
        ProjectHealthReport::class,
        BatchPerformanceReport::class,
        AttendanceReport::class,
        EnrolmentFunnelReport::class,
        CertificateIssuanceReport::class,
    ];

    /** @return Collection<int, Report> */
    public function all(): Collection
    {
        return collect(self::REPORTS)->map(fn (string $report) => app($report));
    }

    /**
     * The reports this person may actually run.
     *
     * A report they cannot open is worse than no entry: it is a door with a
     * lock and no explanation.
     *
     * @return Collection<int, Report>
     */
    public function for(User $user): Collection
    {
        return $this->all()->filter(
            fn (Report $report) => $report->permission() === null || $user->hasPermission($report->permission()),
        )->values();
    }

    public function find(string $key): ?Report
    {
        return $this->all()->first(fn (Report $report) => $report->key() === $key);
    }

    /** The one this person asked for, or a 404 rather than an empty page. */
    public function findFor(User $user, string $key): Report
    {
        $report = $this->find($key);

        abort_if($report === null, 404);
        abort_if($report->permission() !== null && ! $user->hasPermission($report->permission()), 404);

        return $report;
    }
}

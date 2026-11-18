<?php

namespace App\Console\Commands;

use App\Models\MaintenanceContract;
use App\Services\Client\ServiceReports;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Drafts last month's service report for every contract that ran in it
 * (CL17), on the first of the month. A person adds uptime, hours and the
 * work done, and publishes. Safe to run again: drafts are refreshed and
 * published reports are left alone.
 */
class DraftServiceReports extends Command
{
    protected $signature = 'service-reports:draft {--month= : Any date in the month to report on; last month by default}';

    protected $description = 'Draft monthly service reports for maintenance contracts';

    public function handle(ServiceReports $reports): int
    {
        $month = ($this->option('month') ? Carbon::parse($this->option('month')) : now()->subMonthNoOverflow())->startOfMonth();
        $count = 0;

        MaintenanceContract::query()
            ->whereDate('starts_on', '<=', $month->copy()->endOfMonth())
            ->whereDate('ends_on', '>=', $month)
            ->where('status', '!=', 'cancelled')
            ->each(function (MaintenanceContract $contract) use ($reports, $month, &$count) {
                $reports->draftFor($contract, $month);
                $count++;
            });

        $this->info("Drafted {$count} ".str('report')->plural($count)." for {$month->format('F Y')}.");

        return self::SUCCESS;
    }
}

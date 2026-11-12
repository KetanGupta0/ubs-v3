<?php

namespace App\Console\Commands;

use App\Services\Legacy\Reconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Compare the restored v2 copy with what v3 now holds. Run after every
 * import. A difference in a strict check fails the run; see
 * docs/MIGRATION_PLAN.md, section 8.
 */
class LegacyReconcile extends Command
{
    protected $signature = 'legacy:reconcile';

    protected $description = 'Compare the v2 copy with v3 after an import: people, enrolments, money, certificates, attendance, scores';

    public function handle(Reconciler $reconciler): int
    {
        $checks = $reconciler->run();

        $this->table(['Check', 'v2', 'v3', 'Result', 'Source ids to look at'], collect($checks)->map(fn (array $check) => [
            $check['check'],
            $check['source'],
            $check['target'],
            $check['ok'] ? 'ok' : ($check['strict'] ? 'DIFFERENT' : 'see ids'),
            implode(', ', array_slice($check['ids'], 0, 10)).(count($check['ids']) > 10 ? ' …' : ''),
        ])->all());

        $file = 'legacy-reports/'.now()->format('Y-m-d-His').'-reconcile.json';
        Storage::disk('local')->put($file, json_encode(['finished_at' => now()->toIso8601String(), 'passed' => $reconciler->passed(), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('Report: '.Storage::disk('local')->path($file));

        if (! $reconciler->passed()) {
            $this->error('A strict check differs. Do not cut over until it is explained and fixed.');

            return self::FAILURE;
        }

        $this->info('Every strict check matches.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Legacy\Context;
use App\Services\Legacy\Importer;
use App\Services\Legacy\Ledger;
use App\Services\Legacy\Report;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Bring the ubs-v2 data across. See docs/MIGRATION_PLAN.md.
 *
 *   php artisan legacy:import --dry-run --generation-one=/private/gen1.txt
 *   php artisan legacy:import --generation-one=/private/gen1.txt
 *   php artisan legacy:import --only=users --generation-one=/private/gen1.txt
 *
 * The report it writes holds counts and source ids only, so it can be shared.
 */
class LegacyImport extends Command
{
    protected $signature = 'legacy:import
        {--dry-run : Do everything, then roll it back, and report what would have happened}
        {--only= : Comma separated steps to run, e.g. users}
        {--generation-one= : Path to a private file of emails, one per line, of accounts carried into v2 from the first site}
        {--all-generation-one : Treat every account as generation 1: every imported password is replaced}
        {--storage= : Path to a copy of v2\'s storage directory, for the files steps}';

    protected $description = 'Import the ubs-v2 data from a restored copy of its database (the legacy connection)';

    public function handle(Importer $importer): int
    {
        $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));
        $dryRun = (bool) $this->option('dry-run');

        $legacy = config('database.connections.legacy');
        $own = config('database.connections.'.config('database.default'));

        // Two in-memory databases are always separate; anything else that
        // names the same database on the same host is this one.
        if ($legacy['database'] !== ':memory:'
            && ($legacy['driver'] ?? null) === ($own['driver'] ?? null)
            && $legacy['database'] === ($own['database'] ?? null)
            && ($legacy['host'] ?? null) === ($own['host'] ?? null)) {
            $this->error('The legacy connection points at this application\'s own database. Point it at a restored copy of the v2 dump.');

            return self::FAILURE;
        }

        $generationOne = [];

        if ($path = $this->option('generation-one')) {
            if (! is_readable($path)) {
                $this->error("Cannot read {$path}.");

                return self::FAILURE;
            }

            $generationOne = collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
                ->map(fn (string $line) => strtolower(trim($line)))
                ->filter(fn (string $line) => str_contains($line, '@'))
                ->unique()
                ->values()
                ->all();

            count($generationOne)
                ? $this->info(count($generationOne).' generation 1 addresses read.')
                : $this->warn('The generation 1 file lists nobody, so every imported password is kept as it is.');
        }

        $runsUsers = ! $only || in_array('users', $only, true);

        // Getting this wrong would leave a published password working, so a
        // real run refuses to guess.
        if ($runsUsers && ! $dryRun && ! $this->option('generation-one') && ! $this->option('all-generation-one')) {
            $this->error('Say which accounts are generation 1: --generation-one=FILE, or --all-generation-one to replace every password.');

            return self::FAILURE;
        }

        $context = new Context(
            report: new Report($dryRun),
            ledger: new Ledger,
            generationOne: $generationOne,
            allGenerationOne: (bool) $this->option('all-generation-one'),
            storagePath: $this->option('storage'),
        );

        $report = $importer->run($context, $only);

        $rows = collect($report->steps())->map(fn (array $step, string $name) => [
            $name, $step['read'], $step['created'], $step['updated'], $step['unchanged'], $step['skipped'], count($step['warnings']),
        ])->values()->all();

        $this->table(['Step', 'Read', 'Created', 'Updated', 'Unchanged', 'Skipped', 'Warnings'], $rows);

        $file = 'legacy-reports/'.now()->format('Y-m-d-His').($dryRun ? '-dry-run' : '').'.json';
        Storage::disk('local')->put($file, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line('Report: '.Storage::disk('local')->path($file));
        $this->line($dryRun ? 'Dry run: nothing was kept.' : 'Done.');

        return self::SUCCESS;
    }
}

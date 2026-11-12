<?php

namespace App\Services\Legacy;

/**
 * What an import run did, step by step.
 *
 * Written so it can be shared: counts, source table names and source ids,
 * never a name, an email, a phone number or a password. A warning says what
 * happened to which row id, and the person with the data looks it up.
 */
class Report
{
    /** @var array<string, array{read: int, created: int, updated: int, unchanged: int, skipped: int, files: int, warnings: array<int, array{code: string, source: string, message: string}>}> */
    protected array $steps = [];

    public function __construct(public readonly bool $dryRun) {}

    public function count(string $step, string $what, int $by = 1): void
    {
        $this->ensure($step);
        $this->steps[$step][$what] += $by;
    }

    public function warn(string $step, string $code, string $source, string $message): void
    {
        $this->ensure($step);
        $this->steps[$step]['warnings'][] = compact('code', 'source', 'message');
    }

    public function steps(): array
    {
        return $this->steps;
    }

    public function warningCount(): int
    {
        return array_sum(array_map(fn ($step) => count($step['warnings']), $this->steps));
    }

    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'finished_at' => now()->toIso8601String(),
            'steps' => $this->steps,
        ];
    }

    protected function ensure(string $step): void
    {
        $this->steps[$step] ??= ['read' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'files' => 0, 'warnings' => []];
    }
}

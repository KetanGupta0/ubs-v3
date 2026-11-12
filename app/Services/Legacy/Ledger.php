<?php

namespace App\Services\Legacy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes legacy_id_map: which v3 row a v2 row became.
 */
class Ledger
{
    /** @var array<string, object|null> */
    protected array $cache = [];

    /** The ledger entry for a source row, if it was imported before. */
    public function find(string $table, int $id): ?object
    {
        return $this->cache["{$table}#{$id}"] ??= DB::table('legacy_id_map')
            ->where('source_table', $table)
            ->where('source_id', $id)
            ->first();
    }

    /** The v3 id a source row became, or null. */
    public function targetId(string $table, ?int $id): ?int
    {
        return $id === null ? null : ($this->find($table, $id)?->target_id ?? null);
    }

    public function record(string $table, int $id, Model $target, string $checksum, ?int $generation = null): void
    {
        DB::table('legacy_id_map')->updateOrInsert(
            ['source_table' => $table, 'source_id' => $id],
            [
                'target_type' => $target::class,
                'target_id' => $target->getKey(),
                'source_checksum' => $checksum,
                'generation' => $generation,
                'updated_at' => now(),
                'created_at' => $this->find($table, $id)?->created_at ?? now(),
            ],
        );

        unset($this->cache["{$table}#{$id}"]);
    }

    /** A stable fingerprint of a source row, to tell whether it changed since the last run. */
    public static function checksum(object|array $row): string
    {
        $values = (array) $row;
        ksort($values);

        return hash('sha256', json_encode($values));
    }
}

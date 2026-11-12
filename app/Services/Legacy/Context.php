<?php

namespace App\Services\Legacy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Everything a step needs: the source, the ledger, the report and the
 * choices the person running the import made.
 */
class Context
{
    /**
     * @param  array<int, string>  $generationOne  lower-cased emails of accounts carried into v2 from the first site
     */
    public function __construct(
        public readonly Report $report,
        public readonly Ledger $ledger,
        public readonly array $generationOne = [],
        public readonly bool $allGenerationOne = false,
        public readonly ?string $storagePath = null,
        /**
         * How v2 programs meet the v3 catalogue, by v2 slug: a v3 course slug to
         * map onto, or ['type' => 'internship'|'course'|'programme'] for a new one.
         * Never matched by title.
         *
         * @var array<string, string|array{type?: string}>
         */
        public readonly array $courseMap = [],
    ) {}

    public function source(): Connection
    {
        return DB::connection('legacy');
    }

    public function isGenerationOne(string $email): bool
    {
        return $this->allGenerationOne || in_array(strtolower(trim($email)), $this->generationOne, true);
    }
}

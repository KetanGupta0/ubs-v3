<?php

namespace App\Services\Legacy\Steps;

use App\Services\Legacy\Context;

/**
 * One table, or a few that belong together, carried from v2 into v3.
 *
 * A step reads only from the legacy connection, writes only to v3, records
 * every row it makes in the ledger, and reports without personal details.
 */
abstract class Step
{
    /** The name used in --only and in the report. */
    abstract public function name(): string;

    abstract public function run(Context $context): void;
}

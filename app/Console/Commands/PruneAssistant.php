<?php

namespace App\Console\Commands;

use App\Services\Assistant\Assistant;
use Illuminate\Console\Command;

/** Deletes the assistant's conversations once they are older than the period set in the panel (§9.2). */
class PruneAssistant extends Command
{
    protected $signature = 'assistant:prune';

    protected $description = 'Delete website assistant conversations older than the period kept';

    public function handle(Assistant $assistant): int
    {
        $deleted = $assistant->prune();
        $this->info("Deleted {$deleted} ".str('conversation')->plural($deleted).'.');

        return self::SUCCESS;
    }
}

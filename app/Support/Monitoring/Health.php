<?php

namespace App\Support\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether the platform is working, for an uptime monitor and the dashboard.
 *
 * On shared hosting the quiet failures are the ones that matter: a cron entry
 * that stopped running takes the queue, mail, reminders and renewals with it,
 * and nothing on a page looks wrong. So beside the database this checks that
 * the scheduler beat recently and that the queue is not backed up, and says
 * which, by name only.
 */
class Health
{
    /** The scheduler runs every minute; this long without a beat is a fault. */
    public const SCHEDULER_LATE_MINUTES = 10;

    /** A job waiting this long means nothing is working the queue. */
    public const QUEUE_LATE_MINUTES = 15;

    /** Written by the scheduler every minute (routes/console.php). */
    public static function beat(): void
    {
        $file = self::heartbeatFile();

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        touch($file);
    }

    public static function lastBeat(): ?Carbon
    {
        clearstatcache(true, self::heartbeatFile());

        return is_file(self::heartbeatFile()) ? Carbon::createFromTimestamp(filemtime(self::heartbeatFile())) : null;
    }

    /**
     * Each check by name: ok, or what is wrong in a word.
     *
     * @return array<string, string>
     */
    public static function checks(): array
    {
        return [
            'database' => self::database(),
            'scheduler' => self::scheduler(),
            'queue' => self::queue(),
            'storage' => is_writable(storage_path('framework')) && is_writable(storage_path('logs')) ? 'ok' : 'not_writable',
        ];
    }

    /** @param  array<string, string>  $checks */
    public static function healthy(array $checks): bool
    {
        return collect($checks)->every(fn (string $state) => $state === 'ok');
    }

    public static function database(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'unreachable';
        }
    }

    /**
     * Late once it has not beaten for ten minutes. In production a scheduler
     * that never beat at all is late too: the cron entry is missing.
     */
    public static function scheduler(): string
    {
        $beat = self::lastBeat();

        if ($beat === null) {
            return app()->isProduction() ? 'never_ran' : 'ok';
        }

        return $beat->lt(now()->subMinutes(self::SCHEDULER_LATE_MINUTES)) ? 'late' : 'ok';
    }

    /** Only the database queue can be looked into; others answer ok. */
    public static function queue(): string
    {
        if (config('queue.default') !== 'database') {
            return 'ok';
        }

        try {
            $waiting = DB::table(config('queue.connections.database.table', 'jobs'))
                ->whereNull('reserved_at')
                ->where('available_at', '<', now()->subMinutes(self::QUEUE_LATE_MINUTES)->getTimestamp())
                ->exists();

            return $waiting ? 'backed_up' : 'ok';
        } catch (Throwable) {
            return 'unreachable';
        }
    }

    protected static function heartbeatFile(): string
    {
        return storage_path('framework/scheduler-heartbeat');
    }
}

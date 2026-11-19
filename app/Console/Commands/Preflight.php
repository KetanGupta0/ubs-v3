<?php

namespace App\Console\Commands;

use App\Models\LegalDocument;
use App\Models\Permission;
use App\Services\Mail\MailBudget;
use App\Support\Monitoring\Health;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

/**
 * Is this install ready to serve the public? Run after every deploy (Step I).
 *
 * Each line is a pass, a warning (works, but something is missing that the
 * owner meant to have) or a failure (unsafe or broken in production). It
 * exits non-zero on any failure, so a deploy script can stop on it. It reads
 * configuration and says whether a key is set, never what it is.
 */
class Preflight extends Command
{
    protected $signature = 'app:preflight';

    protected $description = 'Check that this install is configured for production on shared hosting';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    protected array $results = [];

    public function handle(Migrator $migrator): int
    {
        $production = app()->isProduction();

        // ----------------------------------------------------------- the app
        $this->check('Environment is production', $production, 'APP_ENV is '.app()->environment().'.');
        $this->check('Debug mode is off', ! config('app.debug'), 'APP_DEBUG=true shows stack traces, and with them settings, to anybody.');
        $this->check('An application key is set', filled(config('app.key')), 'Run php artisan key:generate once, and keep the key.');
        $this->check('The site address is HTTPS', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL is '.config('app.url').'; links in mail and signed links use it.');
        $this->check('Cookies are sent over HTTPS only', (bool) config('session.secure'), 'Set SESSION_SECURE_COOKIE=true.', warnOnly: ! $production);
        $this->check('The time zone is India', config('app.timezone') === 'Asia/Kolkata', 'APP_TIMEZONE is '.config('app.timezone').'; class times and invoice dates would be out.');

        // ---------------------------------------------------------- the host
        $this->check('PHP is 8.4 or later', version_compare(PHP_VERSION, '8.4.0', '>='), 'Running '.PHP_VERSION.'; select 8.4 in hPanel.');
        // The PDO driver is the one the database connection actually uses (MySQL on Hostinger).
        $driver = config('database.connections.'.config('database.default').'.driver');
        $missing = collect(['pdo_'.($driver === 'mariadb' ? 'mysql' : $driver), 'mbstring', 'intl', 'gd', 'zip', 'bcmath', 'fileinfo', 'exif'])
            ->reject(fn ($ext) => extension_loaded($ext));
        $this->check('PHP extensions are loaded', $missing->isEmpty(), 'Missing: '.$missing->implode(', ').'.');
        $this->check('Storage and the cache folder are writable', is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')), 'storage/ and bootstrap/cache must be writable by PHP.');
        $stack = (array) config('logging.channels.stack.channels', []);
        $this->check(
            'Logs rotate and keep a fortnight',
            config('logging.default') !== 'single' && ! in_array('single', $stack, true),
            'Set LOG_STACK=daily (and LOG_LEVEL=warning): a single log file grows until the plan\'s storage is full.',
            warnOnly: ! $production,
        );
        $this->check('Public storage is linked', is_link(public_path('storage')) || is_dir(public_path('storage')), 'Run php artisan storage:link.');

        // ------------------------------------------------------ the database
        $this->check('The database answers', Health::database() === 'ok', 'Check DB_HOST, DB_DATABASE and DB_USERNAME.');

        try {
            $ran = $migrator->getRepository()->getRan();
            $pending = collect($migrator->getMigrationFiles(database_path('migrations')))->keys()->diff($ran);
            $this->check('Every migration has run', $pending->isEmpty(), $pending->count().' waiting; run php artisan migrate --force.');
        } catch (Throwable) {
            $this->check('Every migration has run', false, 'Could not read the migrations table.');
        }

        try {
            $absent = collect(PermissionSeeder::keys())->diff(Permission::query()->pluck('key'));
            $this->check('Every permission is seeded', $absent->isEmpty(), $absent->count().' missing; run php artisan db:seed --class=PermissionSeeder --force.');
        } catch (Throwable) {
            $this->check('Every permission is seeded', false, 'Could not read the permissions table.');
        }

        // ---------------------------------------------------- the background
        $this->check('The queue is a database queue', config('queue.default') === 'database', 'Shared hosting has no worker for '.config('queue.default').'; set QUEUE_CONNECTION=database.');
        $this->check('The scheduler works the queue', (bool) config('queue.from_scheduler'), 'Set QUEUE_FROM_SCHEDULER=true, or mail and notifications never leave.');
        $this->check('The scheduler is running', Health::scheduler() === 'ok', 'Add the one cron entry in docs/HOSTING.md and wait a minute.', warnOnly: Health::lastBeat() === null && ! $production);

        // ------------------------------------------------------- the outside
        $this->check('Mail goes somewhere', ! in_array(config('mail.default'), ['log', 'array'], true), 'MAIL_MAILER is '.config('mail.default').'; nobody would receive a code or a receipt.');
        $this->check('Mail has a sender address', filled(config('mail.from.address')) && ! str_ends_with((string) config('mail.from.address'), '@example.com'), 'Set MAIL_FROM_ADDRESS.');
        $this->check('The daily mail limit is set to the plan', MailBudget::limit() > 0, 'Settings, Mail in the admin panel.', warnOnly: true, pass: 'Mail limit: '.MailBudget::limit().' a day, '.MailBudget::reserve().' kept for service mail');
        $this->check('Payments are configured', filled(config('services.razorpay.key_id')) && filled(config('services.razorpay.webhook_secret')), 'Set the Razorpay keys and webhook secret; until then payments are recorded by hand.', warnOnly: true);
        $this->check('Realtime is the hosted service', config('broadcasting.default') === 'pusher' && filled(config('broadcasting.connections.pusher.key')), 'Chat falls back to polling every few seconds without it.', warnOnly: true);
        $this->check('Error tracking is configured', filled(config('sentry.dsn')), 'Set SENTRY_LARAVEL_DSN so faults reach Sentry (X23).', warnOnly: true);
        $this->check('The content security policy is enforced', config('company.security.csp') && ! config('company.security.csp_report_only'), 'CSP is off or only reporting (CSP_ENABLED, CSP_REPORT_ONLY).', warnOnly: true);

        try {
            $published = LegalDocument::query()->with('versions')->get()
                ->filter(fn (LegalDocument $document) => $document->versions->contains(fn ($version) => $version->status === 'published'))
                ->pluck('key');
            $unpublished = collect(['privacy', 'terms', 'refunds'])->diff($published);
            $this->check('Privacy, terms and refunds are published', $unpublished->isEmpty(), 'Not published yet: '.$unpublished->implode(', ').'. The footer leaves them out until they are.', warnOnly: true);
        } catch (Throwable) {
            $this->check('Privacy, terms and refunds are published', false, 'Could not read the legal documents.', warnOnly: true);
        }

        $this->table(['', 'Check', 'Detail'], $this->results);

        $failures = collect($this->results)->where(0, 'FAIL')->count();
        $warnings = collect($this->results)->where(0, 'WARN')->count();

        if ($failures > 0) {
            $this->error("{$failures} ".str('check')->plural($failures)." failed and {$warnings} ".str('warning')->plural($warnings).'. Fix the failures before the site takes traffic.');

            return self::FAILURE;
        }

        $this->info($warnings ? "Ready, with {$warnings} ".str('warning')->plural($warnings).'.' : 'Ready.');

        return self::SUCCESS;
    }

    protected function check(string $name, bool $passed, string $fix, bool $warnOnly = false, ?string $pass = null): void
    {
        $this->results[] = $passed
            ? ['ok', $name, $pass ?? '']
            : [$warnOnly ? 'WARN' : 'FAIL', $name, $fix];
    }
}

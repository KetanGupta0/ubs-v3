# Hosting: Hostinger shared hosting

The live site (ubs-v2 today, v3 after cutover) runs on **Hostinger shared hosting**.
Every feature has to work inside what that allows. This file says what that means
for this codebase. Read it before adding anything that needs a process, a binary or
a lot of bandwidth.

Numbers (memory, processes, mail per hour, storage, inodes) differ by plan and change
over time. Read them in hPanel for the plan in use, and do not hard-code them here.

**The plan in use (checked by the owner, 2026-11-12):** Business. SSH is enabled (no
root), PHP and MySQL are configured, cron is available, and Hostinger's own mail is
capped at **100 emails a day**.

## What shared hosting does not give us

- **No long-running processes.** There is no `queue:work` worker, no
  `reverb:start`, no Horizon, no Octane, no supervisor. A process that outlives a
  request or a cron run is killed.
- **No root and no system packages.** There is no ffmpeg, no headless Chrome, no
  Redis to rely on, no TURN or media server, and no custom daemons.
- **Limited concurrent PHP processes ("entry processes"), CPU and memory.** Anything
  that keeps a request open, such as long polling, server-sent streams or a long
  export, uses one of the few slots.
- **Mail sending caps** on Hostinger's own SMTP, per hour and per day.
- **Storage and inode caps.** Many small files cost as much as a few large ones.
- **No Node.js runtime to rely on** for building or serving. Assets are built
  elsewhere and uploaded.

## How v3 works inside that

| Need | On shared hosting | Status |
|---|---|---|
| Scheduler | One hPanel cron entry: `* * * * * php /home/USER/path/artisan schedule:run >/dev/null 2>&1`. It runs the queue (below), `lms:remind` every five minutes for class and deadline reminders, the daily renewal reminders (which also bill subscriptions that renew themselves), the hourly report mail, unpaid shop orders lapsing at night, greetings and announcements every five minutes within their budget, the integrity checks on quizzes that have closed every fifteen minutes, and on the 1st of each month the drafts of last month's service reports. "Online now" needs nothing extra: it is `last_seen_at`, written at most once a minute per person. | Works as is |
| Queue (mail, SMS, bulk credentials, notifications) | `QUEUE_CONNECTION=database` and `QUEUE_FROM_SCHEDULER=true`. The scheduler then runs `queue:work --stop-when-empty --max-time=50` every minute (`routes/console.php`). Jobs delayed by a minute go out on the minute they are due. | **Built** |
| Realtime chat, typing, read receipts, proctor status | Reverb cannot run. **Pusher Channels** (decided 2026-11-12): `BROADCAST_CONNECTION=pusher`, `VITE_BROADCASTER=pusher`, plus `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET` and `PUSHER_APP_CLUSTER` (`ap2` is Mumbai), then rebuild assets. Broadcasting is an HTTPS call from PHP, which shared hosting allows. Pusher's free plan caps concurrent connections; past the cap, or with no service at all, chat falls back to polling every 3 seconds. That works, but with many students online it is the quickest way to run out of entry processes, so watch the connection count in Pusher's dashboard during the first live batch. Settings → Providers shows whether it is configured. | **Built**; account and keys to be set up by the owner |
| Cache, sessions, rate limits | Database (`CACHE_STORE=database`, `SESSION_DRIVER=database`), as today | Works as is |
| PDFs (invoices, certificates, documents) | dompdf, pure PHP. PDFs are generated once and cached on disk, never rendered per view. | Works; keep PDFs simple |
| Images | GD re-encoding, as chat already does | Works as is |
| Voice notes | Stored as uploaded; nothing transcodes them, since there is no ffmpeg | Works as is |
| Course video | **Not on the hosting.** Lessons link to a video host (YouTube unlisted, Vimeo, Bunny Stream or similar), and recordings are links on the session. | Rule for Phase B and later |
| Large downloads (source code products) | Small archives can stay on disk behind signed URLs. Anything large goes to S3-compatible object storage with signed URLs. | Decide per product in step F |
| All mail, not only bulk | **Hostinger's 100 a day is not enough for v3 at all.** Cutover alone sends credentials to every generation 1 student (over a hundred), and day to day there are sign in codes, receipts, class reminders and enquiry replies. Every message therefore goes through a transactional provider (for example Brevo, ZeptoMail or Amazon SES) from cutover day, with bounce webhooks (X8); Hostinger SMTP is not used. Credential sends stay throttled by `CREDENTIALS_BULK_PER_MINUTE`; set it under the provider's rate. | **Needed before cutover**; provider to be chosen |
| Live camera proctoring | Needs a media relay (SFU) and a TURN server, which shared hosting cannot run. Use a managed service (for example LiveKit Cloud) or a separate small VPS. Laravel on the shared plan only issues join tokens. | Changes X12 |
| Proctored sittings (§9.1a) | Each student's page sends a small heartbeat every five seconds, so a sitting of 30 is about six short requests a second for its length. The console hears changes through the Pusher-protocol service, one message per change rather than per beat, and asks for the full picture every 8 seconds only while the socket is down. A big group is better as two sittings, or two proctors, than one sitting of a hundred. | **Built** |
| AI (quiz review, visitor assistant) | Outbound HTTPS is fine. Quiz review uses the Message Batches API (`anthropic-ai/sdk`, plain PHP over the HTTP client Laravel already has): `quizzes:ai-review` sends what is waiting and reads back finished batches every fifteen minutes from the scheduler, so no request waits and nothing runs long. The visitor assistant answers each message in one ordinary request of a few seconds, never a stream, because every open stream holds an entry process; it is limited per address, per conversation and per day, and `assistant:prune` clears old conversations nightly. Both are off until `ANTHROPIC_API_KEY` is set. | **Built** |
| Greetings and announcements (§8, X8, X15) | Mail goes through Hostinger's own: 100 a day in all on the current plan. The plan's figure and the part kept for service mail are set in the admin panel (Settings, Mail), every message the mailer hands over is counted against it, and the dashboard shows what is left today. Greetings and announcements are sent by `mail:send-promotional` from the scheduler every five minutes, 15 at a time, only while the day leaves more than the part kept, so a sign in code never waits behind a greeting. Everybody with an account sees the message in the platform at once; a big send takes days by mail. On a larger plan, change the figure in the panel; add a provider's bounce webhook if one is ever set up. | **Built** |
| Selling source code (§4, X16) | Each version is a zip on the private disk, uploaded from the admin panel and capped at `SHOP_MAX_UPLOAD_MB` (100 by default); PHP's `upload_max_filesize` and `post_max_size` in hPanel must allow at least that. Downloads stream through PHP from signed links that last ten minutes, and each licence may fetch a version ten times, which keeps bandwidth predictable. A product's software checks its licence about once a day (`/api/v1/licences/check`, 30 a minute per address), not on every page. Live demos are the owner's own deployments on their own subdomains (X9), never this application. | **Built** |
| Registration forms (§5) | Matching a registration to an account runs in a queued job, so it waits for the next scheduler minute; the visitor's answer does not. A college visit puts many students on one Wi-Fi address, so the limit per address is loose (40 a minute) and the limit per email is what bites (5 an hour). Cloudflare Turnstile is optional (`TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY`); it is an HTTPS call from PHP, and the privacy policy has to mention Cloudflare before the keys go in. Each registration sends one email, which counts against the mail cap above. | **Built** |
| Error tracking (Step I, X23) | Sentry's free plan, reached over HTTPS when a fault happens, so nothing runs in the background. Server faults go through `Sentry\Laravel\Integration`; faults in a browser are posted by the page to `/client-errors` and passed on from the server, so the page loads no third party script. `config/sentry.php` fixes what a report may carry (no request body, cookies, query string, IP or SQL values) and `ErrorReports` scrubs the rest. Set `SENTRY_LARAVEL_DSN` in `.env`, run `php artisan config:cache`, then `php artisan sentry:test` once. | **Built** |
| Uptime (Step I, X23) | UptimeRobot's free plan checks `https://<domain>/health` every five minutes and mails the owner when it fails. `/health` answers 503 naming the check that failed: the database, the scheduler (it beats every minute; ten minutes without a beat is late), the queue (work waiting over a quarter of an hour) or storage. The same checks show in Settings, Providers, and the dashboard says when the scheduler stops. Create an HTTP(s) monitor on that address with a five minute interval and an alert contact. | **Built** |
| Security headers (Step I) | Sent by the application on every response, so nothing is needed in `.htaccess`: a content security policy on every page, `nosniff`, a short referrer, no framing by other sites, and HSTS in production. Violations are reported to `/csp-reports`. After the Razorpay keys go in, make one real payment and check the checkout window opened; if it was blocked, set `CSP_REPORT_ONLY=true` and say what Sentry shows. | **Built** |
| Checking an install (Step I) | `php artisan app:preflight` after every deploy: it fails on what is unsafe in production (debug on, no HTTPS, the queue not run by the scheduler, migrations or permissions missing, mail going nowhere) and warns on what is still to be set up (payments, realtime, Sentry, unpublished legal pages). It exits non-zero on a failure. | **Built** |
| Migration importer | Run over SSH from the command line, where web time limits do not apply, or run locally against the dump and upload the result. The data is small. | Rule for step C |

## Deploying

### Once, before the first deploy

- **PHP 8.4** selected in hPanel, with `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`,
  `bcmath`, `fileinfo` and `exif`.
- **Document root** on Laravel's `public/` directory, not the project root: point the
  domain there in hPanel, or place the project above `public_html` and link
  `public_html` to `public/`.
- **Database:** check which MySQL or MariaDB version the plan provides. Migrations are
  written for MySQL 8, so staging runs on the same engine and version as production.
- **`.env`** from `.env.example`, with at least: `APP_ENV=production`, `APP_DEBUG=false`,
  `APP_URL=https://…`, `APP_KEY` (`php artisan key:generate`, once, then never again),
  `SESSION_SECURE_COOKIE=true`, `QUEUE_CONNECTION=database`, `QUEUE_FROM_SCHEDULER=true`,
  `CACHE_STORE=database`, `LOG_STACK=daily`, `LOG_LEVEL=warning`, the mail settings for
  Hostinger's SMTP, and the provider keys as they arrive (Razorpay, MSG91, Pusher,
  Google, `ANTHROPIC_API_KEY`, `SENTRY_LARAVEL_DSN`). Keep a copy of the whole file in a
  password manager (see Backups).
- **The one cron entry** in hPanel, every minute:
  `* * * * * php /home/USER/path/artisan schedule:run >/dev/null 2>&1`
- **Sentry:** create a free account and a Laravel project, put its DSN in
  `SENTRY_LARAVEL_DSN`, and run `php artisan sentry:test` once after deploying.
- **UptimeRobot:** create a free account and an HTTP(s) monitor on `https://<domain>/health`
  every five minutes, with the owner's email as the alert contact.
- **The mail limit:** Settings, Mail in the admin panel, to the figure the plan allows.
- **Staging:** a subdomain on the same plan, with its own database and `.env`, and mail to
  a sandbox or the log, never to real students.

### Every deploy

1. **Build assets in CI** (GitHub Actions: `npm ci && npm run build`), never on the server.
2. **Take a backup** if the release has migrations (see Backups below).
3. **Upload over SSH**, then run:
   ```
   php artisan down --refresh=15
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan db:seed --class=PermissionSeeder --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
   php artisan storage:link
   php artisan up
   php artisan app:preflight
   ```
   The permission seeder is safe to repeat; new permission keys exist only once it has
   run. `app:preflight` must pass: it exits non-zero on anything unsafe.
4. **Look:** the dashboard says whether the scheduler is running and how much mail is
   left today; Settings, Providers shows every check `/health` makes.

## Backups (X24)

Decided 2026-11-19: Hostinger's own backups only, nothing offsite. That keeps the set up
to nothing; the risk it accepts is that the copies live with the same provider as the
site. Read in hPanel how often the plan backs up and how long it keeps each copy, rather
than relying on a figure written here.

What a working restore needs, and where each piece lives:

| Piece | Where | In Hostinger's backup |
|---|---|---|
| The database | MySQL | Yes, as a database backup |
| Files: documents, invoices and receipts as issued, certificates, chat pictures and voice notes, handouts, source code zips, the media library | `storage/app/` | Yes, with the files |
| `.env`: `APP_KEY` and every provider key | the project folder | Yes, with the files, but keep your own copy too |
| The code | GitHub | Not needed: deploy it again |

**Keep `.env` in a password manager as well.** `APP_KEY` encrypts each person's two-step
sign in secret: with a database restored under a different key, nobody who uses two-step
sign in can use their authenticator app until they sign in with a recovery code and set
it up again, every session is signed out, and the signed links in mail already sent stop
working.

**Before anything risky** (a deploy with migrations, the importer, a bulk change in the
panel), take a copy by hand: in hPanel, Backups, generate a backup of files and
databases; or the database alone over SSH, kept outside `public_html`:
```
mkdir -p ~/backups
mysqldump --single-transaction --no-tablespaces -u DB_USER -p DB_NAME | gzip > ~/backups/ubs-$(date +%F-%H%M).sql.gz
```
Delete old copies now and then: they count against the plan's storage and inodes.

**To restore:**
1. `php artisan down --refresh=15`.
2. Restore the database and `storage/` from hPanel's Backups page, or
   `gunzip < backup.sql.gz | mysql -u DB_USER -p DB_NAME` for the database; put `.env`
   back if it was lost.
3. `php artisan migrate --force` (nothing to do unless the code is newer than the
   backup), then the caches and `storage:link` as in a deploy.
4. `php artisan app:preflight`, then `php artisan up`.
5. Anything paid between the backup and the restore is in the Razorpay dashboard: record
   it by hand (an offline payment), which opens a course or delivers an order as any
   payment does.

**Restore once before cutover, and once a year after:** the latest production backup into
the staging subdomain, with mail to the log; sign in as each role and open an invoice
PDF and a certificate. A backup nobody has restored is a hope rather than a backup.

## Rule for new work

Before adding a feature, check it against "What shared hosting does not give us".
If it needs a process, a system binary, a socket server or sustained bandwidth, it
either goes to a managed service reached over HTTPS, or the plan says why the
shared plan is no longer enough.

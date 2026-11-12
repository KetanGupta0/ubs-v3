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
| Scheduler | One hPanel cron entry: `* * * * * php /home/USER/path/artisan schedule:run >/dev/null 2>&1` | Works as is |
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
| AI (quiz review, visitor assistant) | Outbound HTTPS is fine. Quiz review uses the Message Batches API, polled from the scheduler, so no request waits. The visitor assistant should answer per message without holding a stream open, because every open stream holds an entry process. | Rule for step H |
| Registration forms (§5) | Matching a registration to an account runs in a queued job, so it waits for the next scheduler minute; the visitor's answer does not. A college visit puts many students on one Wi-Fi address, so the limit per address is loose (40 a minute) and the limit per email is what bites (5 an hour). Cloudflare Turnstile is optional (`TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY`); it is an HTTPS call from PHP, and the privacy policy has to mention Cloudflare before the keys go in. Each registration sends one email, which counts against the mail cap above. | **Built** |
| Migration importer | Run over SSH from the command line, where web time limits do not apply, or run locally against the dump and upload the result. The data is small. | Rule for step C |

## Deploying

- **Build assets in CI** (GitHub Actions: `npm ci && npm run build`), never on the
  server.
- **Upload over SSH,** then run `composer install --no-dev --optimize-autoloader`,
  `php artisan migrate --force`, `php artisan db:seed --class=PermissionSeeder --force`
  (new permission keys exist only once it has run; it is safe to repeat),
  `php artisan config:cache route:cache view:cache` and `php artisan storage:link`.
  - SSH access depends on the plan, so check it before step C.
- **Document root:** the domain must serve Laravel's `public/` directory, not the
  project root. Either point the domain there in hPanel or place the project above
  `public_html` and link `public_html` to `public/`.
- **PHP version:** v3's lock file needs **PHP 8.4**. Select it in hPanel, with
  extensions `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `bcmath`, `fileinfo`,
  `exif`.
- **Database:** check which MySQL or MariaDB version the plan provides. Migrations are
  written for MySQL 8, so staging must run on the same engine and version as
  production.
- **Staging:** a subdomain on the same plan, with its own database and `.env`.
- **Mail during staging:** to a sandbox or the log, never to real students.

## Rule for new work

Before adding a feature, check it against "What shared hosting does not give us".
If it needs a process, a system binary, a socket server or sustained bandwidth, it
either goes to a managed service reached over HTTPS, or the plan says why the
shared plan is no longer enough.

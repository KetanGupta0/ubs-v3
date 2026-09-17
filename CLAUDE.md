# Unboundbyte Solutions — working notes

Platform for Unboundbyte Solutions Private Limited. One Laravel application
serving a public marketing site, a client delivery portal, a student learning
management system and an admin panel, from a single sign in.

Read [`docs/PROJECT_PLAN.md`](docs/PROJECT_PLAN.md) before starting anything
substantial. It defines the phases, and each phase is meant to land
independently shippable.

## Stack

Laravel 13, Inertia.js 3, Vue 3 with `<script setup>`, Vite 8, Tailwind CSS 4,
MySQL 8. Pest for tests, Pint for style.

## Running it

```sh
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run dev        # and, in another shell:
php artisan serve
php artisan reverb:start   # and in a third, for live chat
```

MySQL is the target database for every environment. SQLite works for a quick
local run, but test against MySQL before shipping, because migrations are
written for it.

Visit `/design` in a non production environment for the live component gallery.

## Conventions

**Frontend paths are lowercase.** `resources/js/pages`, `components`, `layouts`,
`composables`, `support`. This matches the Inertia default, so no path config is
needed. Import through the `@` alias, never with a relative climb.

**Semantic tokens, not raw palette values.** Components read `var(--surface)`,
`var(--text-muted)`, `var(--border-subtle)` and friends, defined once in
`resources/css/app.css`. Light and dark are defined together in that one file,
which is why almost nothing in the codebase needs a `dark:` variant.

**Every list uses the shared table.** `App\Support\Table\Table` on the server and
`resources/js/components/DataTable/DataTable.vue` on the client. Do not hand roll
a list with its own search box. The shared one already handles search, sorting,
filtering, paging, column visibility, mobile cards and CSV, Excel and PDF export,
and its state lives in the URL so views can be shared.

**A field declared `boolean` comes back false, not null.** `validatedInput()` on
the base controller fills every declared key, and an unticked checkbox is not
sent at all. Where absent should mean true — a document's visibility, whether to
notify — say so with `boolInput($request, 'notify', true)` rather than leaning
on a null.

**Nothing from a request reaches SQL directly.** Sort columns are matched against
a whitelist and filters resolve through their own declared column. Search terms
have their LIKE wildcards escaped. Keep it that way when adding tables.

**Toasts come from flash messages.** A controller calling
`->with('success', '…')` surfaces a toast automatically. Only reach for
`toast.success(…)` in the client for feedback that never touched the server.

## Layouts

`PublicLayout` for marketing pages. `AppLayout` for everything behind a login;
it picks its navigation by role from `resources/js/support/navigation.js`, gives
desktop a collapsible sidebar and phones a bottom tab bar with a More sheet.

Phones are not an afterthought. The target is that a dashboard feels like a
native app, because a mobile app per panel is planned and the web version sets
the expectation.

## Before pushing

```sh
vendor/bin/pint     # style
php artisan test    # suite
npm run build       # catches template and import errors
```

## Authentication

One `users` table for all three roles. `role` picks the dashboard, permissions
decide what a staff account may do once inside.

Every route in converges on `App\Services\Auth\LoginPipeline`: password, one
time code, Google and the mobile API all call `complete()`. Suspension checks,
the two factor gate and the audit entry live there and nowhere else, so a new
sign in route cannot accidentally skip one. Add routes in, never a second
pipeline.

Rules that are easy to break and expensive to get wrong:

- **Never confirm whether an account exists.** Sign in, one time code and
  password reset all answer identically for a real and an unknown identifier.
  Tests assert the messages are byte for byte the same.
- **Codes are hashed, single use, expiring and capped.** Issuing a new one
  consumes the previous one, so a second request replaces the guessing surface
  rather than widening it.
- **Escape LIKE wildcards** in anything a user typed that reaches a query.
- **Sanctum does not enforce abilities by itself.** Authenticated API routes
  carry `abilities:api-access`, which is what stops the short lived two factor
  challenge token opening the rest of the API.
- **Google linking requires a verified Google email.** Accepting an unverified
  one hands an existing account to whoever can claim that address.
- Anything flashed for a single render, such as a two factor secret or a set of
  recovery codes, has to be listed in `HandleInertiaRequests::share` under
  `flash` or it never reaches the client.

Development sends mail and SMS to `storage/logs/laravel.log`. Notifications are
queued, so drain the queue before expecting to read a code from it.

## Public site

Content lives in the database and is seeded from `database/data/*.php`. The
seeder matches on slug and updates in place, so a copy edit re-seeds without
duplicating rows. Those files use single quoted PHP strings, which is right for
copy full of apostrophes, so `\n\n` arrives literally and the seeder converts it.

Rules for the marketing pages:

- **No fabricated proof.** No client screenshots (we have no permission to
  publish any), no invented testimonials, no logo wall. Product pages carry a
  clearly labelled illustrative interface, and the testimonials section renders
  nothing until there is a real quote.
- **Prices are bands, never figures.** A number on a catalogue page is a guess
  presented as a price. Every band is captioned as indicative.
- **Course visibility is enforced server side.** Anything marked `lms_only`
  must not appear in a listing, must 404 by URL, and must stay out of the
  sitemap. There are tests for all three.
- **Internships and courses share the `courses` table** but are different
  products for different people. `type` is `internship`, `course` or
  `programme`; use the `internships()` and `taught()` scopes. Each route checks
  what the row actually is, so an internship 404s under `/training` and a course
  404s under `/internships`. Tests cover both directions.
- **Scarcity has to be real.** Seeded batch fill levels vary per offering. A
  "only 4 seats left" badge on every card reads as a sales trick, which is the
  opposite of what the rest of the site is doing.
- **Every public page renders `Seo.vue`** with a title, description, canonical
  URL and, where it applies, structured data. Descriptions are capped at 158
  characters counted as characters, not bytes.
- Facet lists are ordered by how many products carry each value, because a
  facet list is a browsing aid rather than an index.

## Admin panel

`routes/admin.php`, `App\Http\Controllers\Admin\*`, `resources/js/pages/admin`.

- **The owner holds every permission; nobody else does.** `users.is_owner` is what
  `hasPermission()` checks, and it is deliberately not fillable. Every other
  administrator, staff included, holds exactly what is in `permission_user`, so
  `permission:` middleware actually bites. Staff cannot widen their own
  permissions and cannot restrict or suspend the owner.
- **Creating an account is `App\Services\Admin\AccountCreator`.** It sets a
  temporary password, forces a change before anything else opens, and records a
  `credential_deliveries` row per channel. A delivery failure marks the row failed
  and never rolls back the account: a provider being down is not a reason to lose
  a record somebody just typed in.
- **Every change goes through `App\Services\Admin\Auditor`.** It stores only what
  actually moved, as before and after, with passwords, secrets and tokens replaced
  by dots. There is no route to edit or delete an entry.
- **Provider credentials are never editable from a screen.** The settings page
  shows configured or not configured and nothing else. Keys belong in the
  environment; defaults live in `config/company.php`, never `env()` at the point
  of use.
- **A label reaches its control through `UiFormField`**, which provides the id and
  `aria-describedby` and the inputs inject it. Do not pass an id into `UiInput`
  by hand, and keep `inheritAttrs: false` on controls that render a wrapper, or
  the id lands on the wrapper and the label points at nothing.

## Client module and money

`routes/client.php`, `App\Http\Controllers\Client\*`, `resources/js/pages/client`,
plus `App\Services\Billing` and `App\Services\Payments`.

- **Money is paise, as integers, everywhere.** `App\Support\Money` converts at the
  edges: `Money::toPaise($input)` coming in, `Money::display($paise)` going out.
  A rupee in a float is a rounding error waiting to appear on an invoice.
- **A client's own relations are the only way in.** Every read starts from
  `->forClient($user)` and ends in `firstOrFail()`, so somebody else's record is
  not found rather than found and refused: a 403 confirms it exists. Route model
  binding is deliberately not used for client owned records.
- **The amount comes from the invoice, never from the request.** `Checkout::begin()`
  prices the attempt server side. A checkout that reads its amount from the page
  lets a lakh rupee invoice be settled with one rupee.
- **An invoice freezes who was billed and by whom.** It is issued when the request
  is raised, not when it is paid, and re-rendering it from live settings is never
  correct. The PDF is cached on disk for the same reason.
- **The SLA clock is copied onto the ticket** when it is raised. Reading it back
  off the contract later would let an edit rewrite whether we met the promise.
- **A sent proposal is immutable.** Revise it into a new version instead; the old
  one keeps the answer it was given.
- **An API key is shown once.** Only a hash and the prefix are stored. Lost keys
  are rotated, not recovered.
- **`$request->validate()` drops optional fields the request did not send**, so
  `$validated['project_id']` is a fatal error exactly when the field was left
  blank. Use `$this->validatedInput($request, $rules)` from the base controller,
  which fills every declared key. This has caused three separate 500s.
- **A constrained eager load returns null for anything left out of the select.**
  `with('user:id,name')` then reading `$user->role->value` is a 500, not a blank.

## Learning management

`routes/student.php`, `App\Http\Controllers\Student\*`, `resources/js/pages/student`,
the LMS half of `routes/admin.php`, and `App\Services\Lms`.

- **A lesson opens only if every rule allows it.** `App\Services\Lms\ContentGate`
  holds all four — drip, prerequisite, fee, quiz score — and returns a `LockState`
  carrying the reason. A sealed lesson renders as its reason, never as a 403 and
  never hidden: "locked" on its own is the most annoying word in any learning
  system. Marking a sealed lesson complete is refused, or the gate is decoration.
- **The quiz clock is the server's.** `expires_at` is written when the attempt
  starts; a submission arriving more than `QuizGrader::GRACE_SECONDS` past it is
  marked on what was already stored. The page's countdown is a courtesy.
- **A written answer waits for a person.** It is not scored zero in the meantime,
  and the attempt carries `needs_review` until somebody marks it, because showing
  a student a fail they have not earned is worse than showing nothing.
- **The answer key never reaches an open attempt.** `Question::forAttempt()` is
  what the page gets; `correct` is `$hidden` and is only sent in a review, and only
  if the quiz was set to show answers.
- **Points are awarded once per reason.** `leaderboard_points` is unique on
  student, source and reason, and `Activity::did()` writes the log and the points
  together so the register, the leaderboard and the student's own history cannot
  disagree.
- **Attendance is marked, never inferred.** Marking a register awards the points
  in the same action. A class somebody was marked for is cancelled rather than
  deleted.
- **A certificate is a decision, not a job.** `Credentials::issueCertificate()`
  refuses below the pass mark or the attendance floor unless forced, and a force
  is recorded in the audit log. Revoking keeps the row so the public check still
  answers, and says it was withdrawn.
- **Course fees are inclusive of tax**, because that is the figure the page
  showed. `Money::taxableWithin()` works the taxable amount back out of it.
- **Warnings climb one rung at a time** — notice, warning, escalation — and the
  student is told every time. The private note is for us and never reaches them.
- `/verify` answers identically for a code that never existed and one that was
  mistyped, and shows nothing about the holder beyond what confirms the document.

## Chat

`routes/chat.php`, `routes/channels.php`, `App\Http\Controllers\Chat`,
`App\Services\Chat`, `resources/js/pages/chat`, `resources/js/components/Chat`.

- **One screen for all three roles.** Which rooms somebody sees is a question
  about them, answered once in `Rooms::visibleTo()`. There is no client chat
  controller and no student one.
- **`Conversation::canBeReadBy()` is the only answer to "may they open this".**
  The page, the poll, the media route, the API and the broadcast channel all
  call it. A socket is another door into the same room, so it asks the same
  question.
- **Text, image and audio only.** `messages.kind` is an enum, so the rule holds
  against a future code path, not just against today's controller. An upload's
  type is read from its bytes, never from its name.
- **Images are re-encoded on upload**, which is what strips EXIF. A phone photo
  routinely carries the coordinates of somebody's house.
- **Store first, broadcast second.** `Messenger::announce()` swallows and logs a
  broadcast failure: Reverb being down should cost a message its liveness, never
  its existence. The client polls every three seconds when the socket is down
  and every thirty when it is up, and both paths render through
  `MessagePayload`, so there is one shape for a message.
- **Events are `ShouldBroadcastNow`.** Everywhere else a queued job is right; a
  chat message that waits for a worker is not a chat message.
- **Staff reach a room by permission and join it by replying.** Reading is not
  joining, or an administrator glancing at a thread would collect an unread
  badge for every client on the platform.
- **A removed message keeps its bubble** and says it was removed. The file is
  deleted for real.
- Run the socket server with `php artisan reverb:start`. Without it the chat
  still works, and the header says it is not live.

## Not yet built

Phases 7 through 9 in the plan. Navigation entries that render as "Soon" are
deliberate placeholders, wired but not yet routed. Coupons, instalments,
calendar invitations, message search and push notifications to a phone are named
in the plan and are not built; see the "what landed differently" notes under
Phases 5 and 6 in `docs/PROJECT_PLAN.md`.

There are no JavaScript tests yet. Client only logic is currently verified by
driving a real browser. Bugs in every phase so far have been visible only that
way, so check behaviour in a browser before calling a front end change done.
Phase 4's: an API key was issued and its one readable copy never reached the
screen, because the flash payload was not listed in
`HandleInertiaRequests::share`. That is the same mistake as Phase 1's, which is
why both now have tests. Phase 5's was quieter and worth remembering: every
"Edit" link in the admin catalogue had been 404ing since Phase 3, because those
models bind routes by slug and the screens linked by id. Admin routes now say
`{course:id}` explicitly. A link nobody clicked in a test is a link nobody
tested. Phase 6's was in `config/app.php`: `.env.example` had carried
`APP_TIMEZONE=Asia/Kolkata` since Phase 0 while the config hardcoded UTC, so
every class time and invoice date on an Indian platform was five and a half
hours out.

Chat in particular cannot be called done from PHP tests. Two browser contexts
talking to each other over a real Reverb connection is the test: a message
crossed in 98 ms, the typing indicator and read receipts arrived, and with the
socket server stopped the same message still landed in 2.3 seconds by polling.

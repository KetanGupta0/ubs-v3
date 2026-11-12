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

**Production is Hostinger shared hosting.** No long-running processes, no root, no
system binaries, capped mail and entry processes. The queue runs from the
scheduler (`QUEUE_FROM_SCHEDULER=true` plus one cron entry), realtime goes through
a hosted Pusher-protocol service instead of Reverb, video lives on a video host and
bulk mail goes through a transactional provider. Read
[`docs/HOSTING.md`](docs/HOSTING.md) before adding anything that needs a process, a
binary or a lot of bandwidth.

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

The admin menu is grouped: every admin entry carries a `group` (Overview,
Clients, Learning, Billing, Website, Administration), and a new screen goes in
the group it belongs to, next to its neighbours, never at the bottom. Groups
can be closed and the browser remembers it, as it does the narrow sidebar; the
group holding the current page always opens. `tests/Feature/Navigation` reads
the menus out of `navigation.js` and opens every link, for all three roles.

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
- **"View as" is not a sign in, and it is read only.** `App\Services\Auth\Impersonation`
  lets staff holding `people.view_as` see the platform as a student or client,
  with a reason that goes into the audit log and into that person's own security
  log. It skips `LoginPipeline` on purpose (they did not sign in, so their last
  sign in is untouched). `GuardImpersonation` refuses every non-GET request,
  sign out included, keeps chat closed because opening it marks messages read,
  mutes `Activity` so nothing lands in their record, and ends it after 30 minutes.
  The way back is `POST /viewing-as/stop`.
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
  nothing until there is a real quote. The same goes for people: the team on
  About and the mentors on Training come from `/admin/team`, nobody is seeded,
  and `PeopleGrid` renders nothing, heading included, until a real person is
  added.
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
- **"Enrol now" creates nothing itself.** `/enrol/{course}` remembers the course
  and batch and hands over to the doors that already exist: a signed in student
  goes to the confirm step in their catalogue, a visitor registers or signs in
  first and lands there afterwards. One way into an account, one way onto a
  course, one place the fee is priced. It 404s for anything the public site does
  not list, and sends people back to the course page while enrolment is closed.
- **A page written in the admin panel is blocks, never HTML.** `Page` holds an
  ordered list of heading, text, picture, call to action and questions blocks;
  `SitePageController` keeps only the fields each type allows. Text is Markdown,
  turned into HTML on the server with typed HTML stripped and unsafe links
  refused, and buttons go to a path on this site or an `https://` address only.
  Pages live at `/pages/{slug}` (v2's path too), 404 until published, can be
  previewed by staff with `noindex`, and published indexable ones are in the
  sitemap. `content.manage` is the permission. A picture block takes a
  picture from the media library (`Media`, `/admin/media`) or an upload, which
  joins the library once the whole page is valid. Every library picture has a
  description, and one a page still shows cannot be deleted.
- **A legal document is versions, and a published version is permanent.** Privacy,
  terms and refunds live in `legal_documents` with every version in
  `legal_document_versions`; `LegalDocumentVersion` refuses to change or delete a
  published one, whichever code path asks. Editing starts a draft of the next version,
  publishing needs a note on what changed, and earlier versions stay readable at
  `/legal/{document}/versions/{n}` with `noindex`. The date shown is the day the version
  was published, never today's. A document with nothing published 404s and is left out
  of the footer and sitemap. `legal.manage` is the permission, deliberately not
  `content.manage`. The privacy policy has to be changed before any mail starts that it
  says we do not send.
- **Scarcity has to be real.** Seeded batch fill levels vary per offering. A
  "only 4 seats left" badge on every card reads as a sales trick, which is the
  opposite of what the rest of the site is doing.
- **Every public page renders `Seo.vue`** with a title, description, canonical
  URL and, where it applies, structured data. Descriptions are capped at 158
  characters counted as characters, not bytes.
- **The header and footer menus are set in the panel** (`/admin/menus`,
  `site_menus` and `site_menu_items`), shared to every page as `menus`, cached and
  dropped on save. A menu link must be a path the site's routes answer (a panel page
  or legal document must also be published) or an `https://` address; the check runs
  on save. Do not add links to `PublicLayout` by hand: add them in the panel, or in a
  migration if every install needs them.
- **Contact details reach the public site only when switched on.**
  `App\Support\SiteIdentity` reads the company details kept for invoices and shares
  as `site` only what the Settings "Public site" tab switched on; the same data feeds
  `Seo::organisation()`, so structured data never says more than the page. The PAN is
  never shown. Profile links must be on their own network's host.
- **The words on a fixed page come from `PageCopy::for('page')`,** never from the
  Vue file. Each field is declared, with its starting wording and limits, in
  `database/data/page_copy.php`; edits are drafts in `page_copy` until published, and
  staff preview them with `?preview=copy`. Only words are editable: layout, icons,
  links and list lengths stay in code. Write a number inside a sentence as a
  `{token}` filled from live figures, never as a word.
- **Search wording for the fixed pages is set in the panel,** in `seo_overrides`
  (`/admin/seo`). `Seo::for()` applies it by path, so no controller changes when
  somebody edits it; empty fields keep the page's own wording. The list of fixed
  pages is `SeoOverride::PAGES`: add a new fixed page there. The browser title
  gets the site name once; a title that already has it is left alone.
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
- **A bulk credential send never touches a password somebody chose.** Only accounts
  on a temporary password, or with none (Google only), are sent to, whether or not
  they have signed in here: accounts brought across from the old site keep their
  own password and the old site never recorded sign ins. Each send is recorded as
  queued the moment it is planned, so a second click finds it, and the job checks
  again before it goes. `App\Services\Admin\CredentialBulkSender` holds the rule.
- **Every change goes through `App\Services\Admin\Auditor`.** It stores only what
  actually moved, as before and after, with passwords, secrets and tokens replaced
  by dots. There is no route to edit or delete an entry.
- **An editable message is listed in `App\Support\MessageCatalogue`** with its
  placeholders, the ones it must include and its starting wording, and its
  notification must read the template through `MessageTemplate::find_by_key()`.
  Until November 2026 the enquiry acknowledgement had a template that changed
  nothing when switched on. Saving refuses unknown placeholders and missing required
  ones; the preview and the test send go through the real notification via
  `MessageTemplate::using()`, so they cannot drift from what is sent.
- **Provider credentials are never editable from a screen.** The settings page
  shows configured or not configured and nothing else. Keys belong in the
  environment; defaults live in `config/company.php`, never `env()` at the point
  of use.
- **`UiSelect` takes options either way:** the `options` prop, or `<option>`
  elements written inside it. Until November 2026 it had no slot, so every select
  written the second way rendered empty: question types, lesson prerequisites,
  assignment batches, report filters and the student's batch choice on joining.
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
- **A certificate keeps the look it was issued with.** `CertificateTemplate` sets
  a background, accent, wording and signatory into one fixed layout; the newest
  active design for the course is used, else the default, else the built-in one.
  The certificate records which, and its PDF is cached at issue, so editing a
  design never changes a certificate somebody holds.
- **PDF layouts are written for dompdf, not for a browser.** It ignores `inset`,
  mishandles `bottom`, and a box exactly as tall as the page spills onto a second
  one. Until November 2026 that left every certificate with an open border, no
  signatory, no verification code and no number, plus a blank page. Give each box
  an explicit top, left, width and height, and render it to an image and look.
  Tests assert each certificate is one page.
- **Course fees are inclusive of tax**, because that is the figure the page
  showed. `Money::taxableWithin()` works the taxable amount back out of it.
- **A fee paid in full opens the course in one place,** `Invoicer::recordPayment()`,
  which every payment passes through: online, webhook or recorded by hand. Until
  November 2026 only the online path did it, so a bank transfer recorded by an
  administrator left the paid lessons sealed.
- **Enrolment changes go through `Enroller`**: enrol, move between batches of the
  same course, change status, waive a fee. Moving or dropping recounts seats on
  both batches and puts both batch groups in step. A part-paid fee cannot be
  waived; settle or refund it first.
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

## Reporting, search and saved views

`app/Services/Reports`, `app/Services/Search`, `routes/admin.php` (the reports
group), `resources/js/pages/admin/reports`, `resources/js/components/Charts`.

- **A report is a class, not a page.** It answers with headline figures, chart
  data and the rows behind them, and `ReportController` renders whatever comes
  back without knowing what any of it is about. Adding a report means writing
  one and listing it in `ReportLibrary::REPORTS`.
- **Reports follow permissions**, and one somebody cannot run is not listed —
  and 404s if they type the URL.
- **The rows are the export.** `ReportExport` goes through the same
  `TableExport` every list uses, so a spreadsheet cannot disagree with the
  screen, and the download carries the window the screen is showing.
- **Chart colours are computed, not chosen.** Six hues in a fixed order in
  `resources/css/app.css`, checked for lightness, chroma, colour blind
  separation and contrast in both themes. Status colours — green, amber, red —
  are never series colours: they mean good, warning and bad here. Ordered
  things (age bands, funnel stages) use the one hue ramp, `--chart-step-*`.
- **A report names a slot, never a colour.** `resources/js/support/charts.js`
  maps slots to tokens, so the fixed order lives in one place.
- **Every chart sits in `UiChartFrame`**, which carries the legend and the
  "show the numbers" switch. A chart nobody can read the figures out of is not
  finished.
- **An SVG only reports pointer events over painted pixels**, so a line chart
  needs a transparent rectangle over the plot or the crosshair only appears
  when the cursor happens to be on a two pixel line.
- **Search is scoped by who is asking**, in `GlobalSearch`, which is what lets
  one box serve all three panels. Every href it can produce is opened by a test,
  because a 404 from the search box is worse than no search.
- **A saved view stores the query, not the rows**, and `screen` is checked to be
  a path on this platform before it is stored.

## Not yet built

Phases 8 and 9 in the plan. Navigation entries that render as "Soon" are
deliberate placeholders, wired but not yet routed. Coupons, instalments,
calendar invitations, message search, push notifications to a phone, a visual
filter builder and a report designer are named in the plan and are not built;
see the "what landed differently" notes under Phases 5, 6 and 7 in
`docs/PROJECT_PLAN.md`.

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
hours out. Phase 7's was in a report rather than in code: with no enquiries
recorded, the funnel cheerfully reported "500% of enquiries", because a share of
a base that is zero was still being printed.

Chat in particular cannot be called done from PHP tests. Two browser contexts
talking to each other over a real Reverb connection is the test: a message
crossed in 98 ms, the typing indicator and read receipts arrived, and with the
socket server stopped the same message still landed in 2.3 seconds by polling.

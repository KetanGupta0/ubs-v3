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

**Words meet WCAG 2.2 AA in both themes.** Status and product colours as text use
`var(--text-warning)`, `--text-danger`, `--text-success`, `--text-brand` and the
`text-warning`, `text-danger` and `text-success` utilities, and a product's words use
`accentFor(name).text`. The palette's 500 and 600 steps (`--color-warn-600`,
`text-danger-500`) are for fills, borders and icons: as small text on white they fall
short of 4.5:1. Until November 2026 every form error, overdue date and category label
did. A box with no visible label takes `aria-label` (`UiCheckbox`, `UiProgress`).

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
Each response carrying messages gives them a `flash.id`, and a toast shows once
per id: a partial reload keeps the last page's flash, and until November 2026
that repeated the last toast.

**Notifications are seen in the platform as well as by mail.** Whatever a
notification writes to the `database` channel appears under the bell in the
header and at `/notifications`; opening one marks it read and goes to its `url`.
Give every `toArray()` a `title` and a `url` that is a path on this site. Until
November 2026 the bell was a picture with a dot that never went out, and
nothing written to the database was ever shown.

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

**A query per row fails outside production.** `Model::preventLazyLoading()` is on in
development and in the suite, so a list that reads a relation for each row (an N+1)
throws instead of growing slower with every row. Eager load it with `with()`, or set
the inverse with `chaperone()`. `QueryBudgetTest` holds the pages where a browser
found one that no test had shown.

After a deploy, `php artisan app:preflight` checks the install for production: debug
off, HTTPS, the queue run by the scheduler, migrations and permissions in place, mail
going somewhere, and says which services are still to be set up.

## Authentication

One `users` table for all three roles. `role` picks the dashboard, permissions
decide what a staff account may do once inside.

Every route in converges on `App\Services\Auth\LoginPipeline`: password, one
time code and Google call `complete()`, and the mobile API `completeWithToken()`,
which records the same and issues the device's token. Suspension checks
(`refusalReason()`), the two factor gate (`requiresTwoFactor()`) and the audit
entry live there and nowhere else, so a new sign in route cannot accidentally skip
one. Add routes in, never a second pipeline. Until November 2026 the API had its own
copy, and a temporary password opened the whole app.

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
- **A person's name is letters only (`App\Rules\PersonName`).** It is repeated in the
  mail an account receives, so a form that took anything let bots put an advert and a
  link there for our domain to mail out: three such "clients" came from v2 in November
  2026. Every form that names a person uses the rule; the student sign up has the
  honeypot, the human check and an hourly limit like the other account forms, and the
  importer leaves behind a v2 account whose name carries a link (`spam_name`).
- **Deleting a student or client is `App\Services\Admin\AccountDeleter`,** and only an
  account that holds nothing: almost every key to `users` cascades, so a delete would
  take payments, invoices and certificates with it. Blockers are read from the
  database's foreign keys (a new table blocks until it is listed in `OWNED`), named to
  whoever asked, and suspending is the way to shut such an account. `students.delete`
  and `clients.delete`, audited, permanent so the address is free again.
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
- **A figure only for what is sold at that figure.** Building to order is shown as a
  band, captioned as indicative, because a number there would be a guess presented
  as a price. Source code by licence tier and hosted plans are sold at their price,
  so they show it, always "+ GST" (X17); see Shop below.
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
- **An unpublished catalogue item is a preview for staff and a 404 for everyone
  else.** `App\Support\Preview::unpublished()` opens it for `catalogue.view`, shares a
  banner and `Preview::seo()` adds `noindex`. Visibility rules that are not about
  publishing (`lms_only`, an internship under `/training`) stay 404 for everybody.
- **Old addresses live in `redirects`**, consulted by the 404 handler only
  (`Redirect::forMissing()` from `bootstrap/app.php`), so a redirect never hides a real
  page and only GET is sent on. Add one there rather than a route.
- **Search wording for the fixed pages is set in the panel,** in `seo_overrides`
  (`/admin/seo`). `Seo::for()` applies it by path, so no controller changes when
  somebody edits it; empty fields keep the page's own wording. The list of fixed
  pages is `SeoOverride::PAGES`: add a new fixed page there. The browser title
  gets the site name once; a title that already has it is left alone.
- Facet lists are ordered by how many products carry each value, because a
  facet list is a browsing aid rather than an index.
- **The assistant answers from the catalogue and passes nothing on by itself (§9.2).**
  `App\Services\Assistant\Assistant` runs it on `company.ai.assistant_model`, a whole
  answer per message and never a stream, because a stream holds an entry process;
  `App\Services\Ai\ClaudeChat` is the only class that touches the SDK. Its tools
  (`SiteTools`) read the catalogue by the public pages' rules (nothing unpublished, no
  `lms_only`, no form for an LMS only course) and give prices as the pages do.
  `offer_contact` only puts details on a card: a lead is made when the visitor presses
  Send (`confirmLead()`), and every lead, wherever it comes from, is announced through
  `LeadNotifier`. Replies are Markdown made safe on the server, linking only to this
  site's paths. It is off until switched on under Website → Website assistant, which
  needs `ANTHROPIC_API_KEY` and a published privacy version at least
  `assistant.policy_version`, and a visitor agrees to its notice before anything is
  sent. A conversation takes twenty messages (`Assistant::MAX_VISITOR_MESSAGES`); how
  many conversations a day and how long they are kept (at most 90 days, as the policy
  says) are set there. `assistant:prune` deletes old conversations, never the leads.

## Admin panel

`routes/admin.php`, `App\Http\Controllers\Admin\*`, `resources/js/pages/admin`.

- **Every admin route names a permission,** apart from the dashboard and reports (which
  check each report's own), every route that changes something without a sign in is
  throttled or signed, and every API route but signing in and the licence check needs a
  full token. `tests/Feature/Security/RouteAuditTest.php` reads the router, so a route
  added without its guard fails there.
- **The owner holds every permission; nobody else does.** `users.is_owner` is what
  `hasPermission()` checks, and it is deliberately not fillable. Every other
  administrator, staff included, holds exactly what is in `permission_user`, so
  `permission:` middleware actually bites. Staff cannot widen their own
  permissions and cannot restrict or suspend the owner. The owner is named from the
  shell with `php artisan app:owner {email}` (`--replace` hands it over), audited; no
  screen sets it.
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
- **A bulk credential send keeps inside the day's mail limit (X15).** It shares what the
  day leaves above the part kept for service mail (`MailBudget::bulkLeft()`) with
  greetings and announcements. A `SendCredentials` job whose mail does not fit puts itself
  back until nine the next morning without touching the password, and retries for a month
  rather than three times; when it goes it sends at once (`notifyNow`), so each message is
  counted before the next is weighed. Mail and SMS go together, with the one password.
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
  An option for nothing, `<option :value="null">` (Every batch, No prerequisite,
  Decide for me), shows as chosen when the value is empty and sends `null`. Vue
  drops the value attribute of an option bound to null and the browser falls back
  to its text, so until November 2026 nothing showed as chosen and picking it sent
  its words, which every one of those fields refuses: a quiz, assignment, material
  or certificate design set for one batch or course could not be set back, and a
  lesson's prerequisite or quiz gate could not be taken off.
- **`UiInput` honours `v-model.number` and `.trim`.** Until November 2026 it declared
  neither, so every number field sent a string and the modifier landed on the element
  as `modelmodifiers="[object Object]"`.
- **`UiCheckbox` binds to a boolean or to a list.** With `v-model` on an array
  and a `:value`, it adds or removes that value. Until November 2026 the list form
  was declared and never handled, so every box showed ticked and the first click
  replaced the list with `true` or `false`: the staff screen could not grant a chosen set of
  permissions at all, inviting or editing.
- **A label reaches its control through `UiFormField`**, which provides the id and
  `aria-describedby` and the inputs inject it. Do not pass an id into `UiInput`
  by hand, and keep `inheritAttrs: false` on controls that render a wrapper, or
  the id lands on the wrapper and the label points at nothing.
- **A dialog that opens empty calls `blankForm()`** (`resources/js/support/forms.js`).
  Inertia 3 makes whatever was last submitted a form's defaults, so a bare `reset()`
  as a dialog opens brings back the previous entry. Until November 2026 that refilled
  a student notice, a registration's rejection reason (which the student reads), an
  enrolment's payment note and waiver reason, a chat silence reason, an extra attempt
  grant and a new client member's details with whatever was typed the time before.
  A `reset()` inside `onSuccess` is fine: it runs before the defaults move.

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
- **A client is an organisation (CL8).** The account that holds the records is the
  organisation; other people join it in `client_members` as owner, signatory,
  finance or technical. `ClientController::client()` returns
  `$request->user()->clientAccount()`, which is the one place the rule above widens;
  the person is still `$request->user()` (who raised a ticket, who paid). The API and
  search resolve the same way. Everybody in it reads everything; what a role may do
  is `ClientMember::ABILITIES`, refused on the server with `mayAsClient()` and left
  off screens through `auth.client.may`. Invoices go to finance, proposals to
  signatories (`clientRecipients()`). Staff pick and bill `clientAccounts()` only,
  so nothing is filed under a member. Bringing somebody in never confirms whether an
  address has an account: `App\Services\Client\Organisation` answers the same, and
  an existing account has to accept.
- **The amount comes from the invoice, never from the request.** `Checkout::begin()`
  prices the attempt server side. A checkout that reads its amount from the page
  lets a lakh rupee invoice be settled with one rupee.
- **An invoice freezes who was billed and by whom.** It is issued when the request
  is raised, not when it is paid, and re-rendering it from live settings is never
  correct. The PDF is cached on disk for the same reason.
- **A client's ticket is raised through `App\Services\Support\Tickets`,** from the portal
  and the app alike: the contract and project re-resolved through the client's own
  relations, the clock copied from the contract, the desk told. Until November 2026 a
  ticket raised from the app told nobody.
- **A ticket belongs to whoever raised it** (`support_tickets.requester_id`): a client,
  or a student at `/student/support`. A student's ticket has no contract and so no
  response clock. `client()` on the model is the same relation, kept for the client code.
- **The SLA clock is copied onto the ticket** when it is raised. Reading it back
  off the contract later would let an edit rewrite whether we met the promise.
- **A sent proposal is immutable.** Revise it into a new version instead; the old
  one keeps the answer it was given.
- **A sent document is a file, not a render (CL3).** `App\Services\Client\ClientDocuments`
  freezes a proposal or an agreement as it goes out: the parties are copied onto the
  row, the PDF is stored with its SHA-256, and every later download is that file. A
  draft previews and is never kept. The hash is what a signature is a signature of.
- **An agreement's text is permanent once sent (CL1).** `Agreement` refuses to change
  any of `Agreement::FROZEN` or to be deleted after it leaves draft, whatever code path
  asks. A change is a new version (`previous_id`); the one it replaces is marked
  superseded when the new one is sent, and stays readable.
- **A signature is a typed name and a one time code over the PDF as sent (CL2).**
  `App\Services\Client\Signatures` sends the code to the signer's own address, refuses
  when the stored PDF no longer matches its SHA-256, and records the signer, time, IP and
  browser in `document_signatures`, which refuses any change or delete. Accepting a
  proposal is signing it; `respond` only declines. A signatory or an owner signs, and
  staff countersign an agreement only with `agreements.countersign`, because that binds
  the company. Each signature renders a separate signed copy with a certificate page;
  the PDF as sent is never touched. "Opened" is the first time somebody in the client's
  organisation opened it, never staff and never "view as".
- **Accepting a milestone raises its invoice, once (CL9).** `App\Services\Client\MilestoneSignoffs`
  asks the client's signatories to accept a milestone, with the deliverables copied onto
  the request in `milestone_signoffs`. A signatory or an owner accepts it, or asks for
  changes with a reason, and every round is kept. Accepting raises the milestone's
  `payment_amount` (before tax) as a payment request and invoice through the usual path,
  unless a live one already exists for that milestone, however it was raised. An accepted
  milestone cannot be edited, reopened or deleted, whatever code path asks.
- **What we need from a client is a "waiting on you" item (CL10).**
  `App\Services\Client\WaitingItems` raises it with a due date and whether work waits
  for it, and tells the people it concerns: a decision goes to signatories, access to
  technical people, the rest to everybody. Anybody in the organisation replies, with
  words, files or both; files join the organisation's documents as "From the client",
  their type judged from their bytes. Every step is a `waiting_item_events` row, and the
  time a project waited is the union of the periods blocking items were open, so two
  things asked for at once count once. A request for access says never to type a password.
- **Minutes are versions, and a published version is permanent (CL11).**
  `App\Services\Client\Meetings` schedules a meeting and tells the organisation when it
  is set, moved or called off. Minutes are written as a draft the client cannot see and
  published after the meeting has started; `MeetingMinutes` then refuses to change or be
  deleted, whatever code path asks. Anybody in the organisation acknowledges the latest
  version or says what is wrong, and a correction is the next version, acknowledged
  afresh. What the client agreed to do becomes a "waiting on you" item, raised once
  however many versions repeat it.
- **A change to the scope is an addendum the client signs (CL12).**
  `App\Services\Client\ChangeRequests`: anybody in the organisation asks; staff quote an
  amount (before tax) and days, which freezes the request as a PDF with its hash like any
  sent document, and `ChangeRequest` refuses to change after that. A signatory or an owner
  approves by signing it through `Signatures`, or declines with a reason. Approval, in the
  signing transaction, adds the amount to the project's budget, moves its target date and
  adds the work as a milestone carrying the amount, so it is invoiced on acceptance (CL9)
  like any other. A different estimate is a new request (`previous_id`).
- **Staging feedback is reported by those who may (CL13).** `App\Services\Client\StagingFeedback`:
  the organisation's technical people and owners (`feedback`) report a bug or a change
  with up to three screenshots, re-encoded through `ImageUpload` so nothing that came
  with the file survives, and the milestone it holds up; everybody in it can read. Our
  side moves an item only along `StagingFeedback::STAFF_MOVES`, and declining needs a
  reason. A fix is the client's to confirm, or to send back saying what still happens.
- **The client dashboard leads with what needs them:** items to reply to, milestones to
  accept, proposals and agreements to sign, payments, renewals. Something only another
  role can do still shows, saying whose it is. An overdue invoice whose payment request
  is still open is the same debt and is listed once.
- **An invoice is settled by more than money (CL14).** `Invoice::balance()` is the total
  less what was paid, the TDS the client deducted and credit notes, and
  `Invoicer::settle()` is the one place that turns that into the invoice's status and
  marks its request paid. TDS is declared by finance or an owner before paying, at a
  section rate from `config/company.php` on the taxable value (never the GST), and the
  client pays the net; the Form 16A is chased by quarter and checked against Form 26AS.
  `Checkout` reuses an attempt only at the current amount. The client keeps its own
  legal name, GSTIN and state (`/client/billing-details`, the state picked from
  `IndianStates`, the GSTIN's code checked against it), and an invoice copies them, and
  the PO number, when it is issued.
- **A credit note is a tax document of its own (CL14).** `App\Services\Billing\CreditNotes`
  never edits the invoice: it issues a note in its own `CN/{year}/{n}` series, with the
  parties copied from the invoice and tax at the invoice's own rates, for no more than
  the taxable value left to credit; `CreditNote` refuses to change or be deleted. The
  statement of account (`App\Services\Billing\Statement`) is drawn from the ledger on
  demand: invoices and refunds paid out are debits; payments, TDS and credit notes are
  credits; cancelled invoices were never owed.
- **Nothing of a client's lapses unnoticed (CL16).** `renewals:remind`, daily, reminds at
  30, 14 and 3 days about subscriptions, maintenance contracts and `RenewalItem`s
  (domains, hosting, certificates, update windows), each once per window, to the
  organisation's finance or technical people; a domain or certificate, which takes a
  site down, goes to our side too. A subscription set to renew itself is billed on its
  date, before tax at the usual rate, under a row lock so it is billed once, and moved
  on a period. Notes on a renewal say where access is kept, never what it is (X6).
- **A handover says where access was shared, never what it is (CL15, X6).**
  `App\Services\Client\Handovers` refuses on save anything that looks like a password,
  key or token (`SECRET_PATTERNS`), and publishing fixes what the client sees as a
  revision in `published_content`, so later edits are drafts until published again. A
  signatory or an owner acknowledges each revision. After that a short survey (CL18)
  asks for a rating; a quote becomes a testimonial draft only with the client's written
  consent, credited exactly as they gave, and is published by a person.
- **A service report is worked out, then finished by a person (CL17).** `service-reports:draft`
  drafts last month's report for every contract on the 1st, with the ticket figures from
  `App\Services\Client\ServiceReports`: response and resolution judged against the
  times copied onto each ticket, and a ticket still open but not yet due left undecided
  rather than counted as missed. Uptime, hours used and the work done are written in by
  our side; publishing files the PDF in the client's documents, and a published report
  is never redrafted.
- **Signing and paying for an organisation need two-step sign in (CL20).** `mayAsClient()`
  refuses `User::COMMITTING_ABILITIES` (sign, approve, accept, pay) to anybody without it
  while `company.clients.two_factor_to_commit` is on (`CLIENT_TWO_FACTOR_REQUIRED`, on by
  default), and the layout says so before anybody is refused. The suite runs with it off
  (`phpunit.xml`) and `ClientSecurityTest` tests the rule itself. Owners see who in their
  organisation did what at `/client/activity`, from the audit log by their own people
  only, with payments and sign ins; everybody manages their own sessions and devices
  under Security.
- **An API key is shown once.** Only a hash and the prefix are stored. Lost keys
  are rotated, not recovered.
- **`$request->validate()` drops optional fields the request did not send**, so
  `$validated['project_id']` is a fatal error exactly when the field was left
  blank. Use `$this->validatedInput($request, $rules)` from the base controller,
  which fills every declared key. This has caused three separate 500s.
- **A constrained eager load returns null for anything left out of the select.**
  `with('user:id,name')` then reading `$user->role->value` is a 500, not a blank.
- **A relation that sorts keeps its order.** `minutes()`, `events()` and `signoffs()`
  sort oldest first, so `->orderByDesc()` after them only adds a second sort key and
  `first()` still returns the oldest. Use `reorder('version', 'desc')`. Until November
  2026 that made a client's acknowledgement land on the version they had disputed.

## Shop

`routes/shop.php`, `App\Http\Controllers\Shop\*`, `App\Services\Shop`, `resources/js/pages/shop`,
the selling page of a solution in the admin catalogue, and Orders in the client portal.

- **What a solution is offered as is `Solution::OFFERINGS`:** built to order, the source
  code by licence tier, hosted as a plan, a live demo. `offers()` says whether the site
  really offers one: switched on with nothing on sale behind it (no live tier, no demo
  address) offers nothing. Prices are paise before tax (X17).
- **The cart holds choices, never prices.** `CartStore` keeps it in the session for a guest
  and on the account once signed in (`MergeGuestCart`, on `Login`, whichever way in).
  `Pricing::quote()` prices it from the catalogue every time and is the only sum: the cart
  shows it, and `Orders::place()` makes the order from it and refuses when the total is not
  the one the buyer was shown. What went off sale is taken out and said so once.
- **Codes are the server's (`Coupons`).** Checked when the cart is priced and again, with the
  coupon rows locked, when the order is placed. A use held by an unpaid order counts for
  `shop.coupon_hold_minutes`, so an abandoned checkout frees a limited code by itself. Codes
  combine only if each is stackable; each applies to what is left of its lines, so a stack
  never takes more than a line is worth, and every line carries its share to the paisa.
- **One way into an account at checkout (CL6).** A guest asks for a code by email, and the
  answer and the message are the same for any address. The code, once typed, signs a client
  in through `LoginPipeline`, makes a new client through `AccountCreator::createForBuyer()`
  (verified, no password: they sign in by code), and turns away staff and student
  addresses. Only a client account places an order.
- **An order is paid like everything else.** It raises a payment request whose payable is the
  order; the invoice is issued when paying starts, a line per item with its SAC, the order's
  discount and its frozen tax split. Whoever placed an order may pay it without the finance
  role or two-step sign in: they pay with their own card. `Invoicer::markRequestPaid()` calls
  `Orders::fulfil()`, so money recorded by hand delivers an order as an online payment does.
  Unpaid orders lapse after `shop.order_expiry_days` (`shop:expire-orders`), and an order
  with a payment started in the last half hour cannot be cancelled by the buyer.
- **A licence key is shown once (CL4).** Made the first time somebody asks, stored as the
  SHA-256 of its letters and digits (dashes, case and I/L/O typos do not matter), handed over
  in the `issuedLicenceKey` flash. A lost key is replaced and the old one stops working; the
  sites keep their places. Technical people, owners and whoever bought it use keys, sites
  and downloads (`licences`).
- **Downloads are signed links that expire (X16).** The portal makes one for a licence, a
  version and a person; the download checks again, counts it (`shop.downloads_per_version`)
  and streams from the private disk. A licence downloads what was released inside its update
  window. A version somebody downloaded is withdrawn, never deleted.
- **Software checks its key at `POST /api/v1/licences/check` (X18).** An unknown key and a key
  for another product get the same answer; a site takes a place the first time it checks, up
  to the tier's limit; development hosts take none; the answer says when to ask again (a
  day). `docs/LICENCE_API.md` is what the products' developers read.
- **A refund of the whole order withdraws what it gave** (`Orders::refundSettled()`, when a
  refund completes): licences are revoked, plans stop renewing. Part of it changes nothing.
- **A demo is the owner's to host (X9).** Its sign in is public by design. Opening it is a
  plain link and records nothing; asking for a walkthrough, with consent ticked, is the only
  way it becomes a lead (`interest` `demo`).
- **Our side of it is under Billing:** Shop orders, Licences and Discount codes, with
  `orders.view`, `orders.manage` (cancel an unpaid order, withdraw, restore or extend a
  licence, free a site) and `coupons.manage`, and the Shop sales report. A code somebody used
  keeps its name and is switched off, never deleted; a licence's updates are extended, never
  cut short (withdraw it instead). The key never appears on our side.
- **An unnamed throttle counts for its own route** (`App\Http\Middleware\ThrottleRequests`,
  aliased as `throttle`). Laravel's shares one count per address with every other unnamed
  one, so until November 2026 applying two codes used up the attempts at asking for a
  checkout code. A name (`throttle:6,1,checkout-code`) is how routes share a count on
  purpose.

## Mail: greetings and announcements

`App\Services\Mail`, `App\Http\Controllers\Mail`, the mail screens under Website in the
admin panel (`/admin/mail/*`), `mail:send-promotional` in the scheduler.

- **Promotional mail is kept apart from service mail.** Greetings and announcements go
  only through a send (`MailCampaign`); they read the do-not-mail list
  (`MailSuppression`, by address) and nothing else does, so stopping them never stops a
  sign in code, a receipt or a reminder.
- **The day's mail is counted against the plan's limit (X8, X15).** Hostinger's plan
  allows 100 a day in all. The owner sets the plan's figure, and the part kept for service
  mail, under Settings, Mail (`App\Services\Mail\MailBudget`; 100 and 40 to start), and
  the dashboard shows what is left today. `CountMailSent` counts every message the mailer
  hands over, by recipient, in `mail_usage`; `CountMailRefused` counts a queued one the
  server refused once its retries were spent. The count never holds service mail back:
  the mail server decides, and mail sent by hand from the same mailbox counts there and
  not here.
- **Greetings and announcements take only what the day leaves above that part,** from the
  scheduler a few at a time, greetings before announcements and people without an
  account first, because mail is their only way to hear. Nobody gets more than
  `company.mail.promotional_per_week` in a week. Everybody with an account sees the same
  message among their notifications as soon as a send starts, whatever the budget.
- **A send fixes who it is for when it starts** (`Campaigns::start()`), one address once,
  from `Audience` filters; the list and the weekly limit are checked again just before
  each mail goes. Staff are never in an audience.
- **Every one says why it came and how to stop it.** The footer names the reason; the
  link is signed over the path (`signed:relative`) and never expires; opening it shows one
  button, because mail scanners open links; the `List-Unsubscribe` headers let a mail app
  post straight to it (RFC 8058), which is why `unsubscribe/*` is out of CSRF. Account
  holders have the same switch under Security and on each message. An address stopped
  because it bounced comes off the list only by staff.
- **People who only sent an enquiry are mailed only once the privacy policy saying so is
  published (X19)** and somebody switches it on under Announcements, ticking that the
  policy tells them; until then `Audience` leaves them out. Version 2 of the policy is
  drafted by a migration for the owner to read and publish; it is never published by code.
- **A greeting goes on its day only.** An occasion with the same date every year moves on
  by itself; a festival moves on a year as a guess and is not sent until somebody confirms
  the date, with a reminder a month before. A day the scheduler missed is skipped, never
  sent late, and what the budget did not reach by midnight is not sent.
- Opens are not tracked: no pixel, no provider yet. Bounces are added to the list by hand
  until a provider's webhook can.

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
  if the quiz was set to show answers, and, where it waits, once the quiz has closed
  for everybody (`answersVisible()`).
- **A quiz paper is fixed when the attempt starts (§9.1).** `QuizGrader::start()`
  draws the pool and orders the questions and each one's options, and keeps them on
  the attempt (`question_ids`, `option_orders`), so a refresh changes nothing and the
  marking uses that paper. An answer is the option's words, as the key is; the order
  is only what is shown. Until November 2026 the page sent an option's place in the
  list while the editor, the seeder and the importer key its words, so every one
  answer and several answers question taken through the page was marked wrong.
- **Answers are kept as they are given,** each with the seconds since the one before,
  capped, so a refresh or a dropped connection loses nothing. On a quiz with no going
  back a question closes when the student moves on, and neither saving nor the
  submission can change it.
- **An attempt is open in one browser at a time.** The browser is a random token in
  the `quiz_device` cookie, kept in the session too, and only its hash is on the
  attempt; a cookie, so signing out and in again is the same browser. Another browser
  is told, and may carry on there, which is noted and stops the first one saving or
  submitting. A new network is noted, never refused.
- **What the browser reports is evidence, not fact.** Only
  `QuizAttemptEvent::FROM_BROWSER` types are taken from it (leaving the quiz, pasting,
  copying), capped per report and per attempt. The server writes the start, every
  answer, the submission and a change of browser or network itself.
- **Integrity checks find; a person decides.** `IntegrityChecks` runs once a quiz has
  closed and the last sitting is over (`quizzes:check-integrity`). A flag never
  changes a score, fails anybody or reaches the student, and the student is told
  before starting what is noted (`Quiz::rulesForStudents()`). Time on a question
  counts only when it was the one question on the screen; on a one page quiz the
  whole sitting is judged, because people read ahead and then answer in a run.
- **A proctored quiz is started in a sitting, agreed to, on a computer (§9.1a).**
  `App\Services\Lms\Proctoring` holds the rules: `refusal()` (a phone or tablet, Safe
  Exam Browser missing, no sitting open for the student's batch) and `begin()`, which
  ties the attempt to its `quiz_slots` row, records the agreement and ends the clock
  with the sitting at the latest. The camera (`live`) is refused until there is a
  video relay (X12), and the guardian consent and webcam exemption of X4 come with it.
- **The lockdown notices; the server counts.** `useLockdown` keeps the page in full
  screen, covers the questions whenever the student leaves full screen, the tab or the
  window, switches off copying, pasting, the right click, dragging and the shortcuts
  for the developer tools, printing and going back, and reports each try. The page is
  the bare `ExamLayout`, because every link on screen is a way out. Exits are counted
  on the server from the event log, one departure once however many pages report it
  (`AttemptEvents::away()`): a reload made one leaving count twice until November 2026.
  A quiz with `max_exits` is submitted for the student at that count.
- **A proctored page beats every five seconds** with whether it is in full screen and
  in focus, and hears back the proctor's unread messages, the clock and whether the
  attempt has ended. The console (`quiz.proctor`) gets changes on `quiz-proctor.{id}`
  at once (`ProctorUpdate`: stored first, broadcast second) and polls besides. A proctor
  messages, adds time (an hour at most at once) or ends an attempt, each with a reason
  through `Auditor`; an ended attempt is marked as it stands, the student is told why,
  and a resit is the trainer's decision.
- **The AI reads a flagged attempt with nobody named (§9.1, layer 3).** `AiReview` sends
  flagged attempts, or one a trainer asks about, through the Message Batches API at half
  price; `App\Services\Ai\ClaudeBatches` is the only class that touches the SDK, and the
  tests stand in for it. `payload()` labels students A, B, … and takes names, emails and
  numbers out of free text; no address or id goes. The answer is held to
  `AiReview::SCHEMA` (a risk, evidence pointing at something specific, the innocent
  readings weighed), and the prompt forbids saying an answer was written by an AI. It
  advises a person, never changes a score, never reaches the student, and everything
  around it works without `ANTHROPIC_API_KEY`.
- **A person decides, and the student is told (§9.1, layer 4).** `IntegrityDecisions`:
  cleared, to discuss, voided (with a resit if allowed) or a warning on the ladder, each
  audited. The student reads the outcome and the reason, never the note, and replies
  once before it is final; a first decision to clear is final and not sent, because
  nothing was ever said to them. A voided attempt stays on the record and counts for
  nothing: read scores through `QuizAttempt::counted()` (best attempt, content gate,
  performance, result card), and a quiz's points follow the attempts that count
  (`recountPoints()`), so changing the decision gives back what voiding took.
- **Safe Exam Browser is checked on every request of the attempt (X10).** Each carries
  a SHA-256 of its own full address followed by the configuration key or the browser
  exam key, compared in `Proctoring::inSafeExamBrowser()` with the keys on the quiz; the
  settings file reaches SEB by a signed link, because SEB fetches it signed out. The
  suite can only fake the header, so a final assessment is tried in SEB itself first.
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
  an accent, wording and signatory into one of the fixed layouts in
  `CertificateTemplate::LAYOUTS`; the newest active design for the course is used,
  else the default, else the built-in one. The certificate records which, and its
  PDF is cached at issue, so editing a design never changes a certificate somebody
  holds.
- **Classic is the only layout that takes a background.** Regal, Midnight, Heritage and
  Laurel (`resources/views/credentials/certificates`) are drawn whole: gold ornaments
  from `resources/certificates/render-art.cjs` and the OFL typefaces in
  `resources/fonts/certificates`, both through `App\Support\CertificateArt`, with fonts
  subset into each PDF and their metrics kept in `storage/fonts`. An accent prints only
  where it reads at 4.5:1 (`CertificateArt::ink()`), else the layout's own colour. A
  migration added one design in each, switched off: nothing issued changes until
  somebody turns one on. A layout that paints the page fills all 210 mm with a `fixed`
  box outside the 208 mm sheet. The pictures on the form come from
  `php resources/certificates/render-thumbnails.php`; run it after changing a layout.
- **Anything printed carries the permanent address.** Certificates and internship letters
  put `App\Support\Printed::url()` in their QR code and "verify at" line:
  `company.printed_url` (`PRINTED_URL`, `https://unboundbytesolution.com`) when set, else
  the address the site is reached at. Set it while the site runs on a temporary domain, or
  everything printed there points at it for good; `app:preflight` warns. A PDF view never
  writes `url('/verify')` itself, and `PrintedAddressTest` fails one that does.
- **PDF layouts are written for dompdf, not for a browser.** It ignores `inset`,
  mishandles `bottom`, and a box exactly as tall as the page spills onto a second
  one. Until November 2026 that left every certificate with an open border, no
  signatory, no verification code and no number, plus a blank page. Give each box
  an explicit top, left, width and height, and render it to an image and look.
  Tests assert each certificate is one page.
- **Every PDF carries the company logo,** through `App\Support\PdfBrand::img($mm)`: the
  BrandLogo lockup as a PNG (`public/brand/logo-pdf.png`, made by
  `node resources/brand/render-pdf-logo.cjs`), handed to dompdf as data, because dompdf
  has neither the Sora typeface nor reliable SVG gradients; on a dark ground,
  `img($mm, light: true)` gives the white lockup. `PdfBrandTest` lists every
  view a PDF is rendered from and fails one without the logo, so a new PDF goes on that
  list. Pages keep 12 mm margins (letters 14 to 16 mm). A PDF already issued keeps the
  look it was issued with.
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
- **A registration form says the same thing to everybody.** `/join/{slug}` answers
  every valid submission with one redirect and "check your email", and the matching
  happens afterwards in `ProcessRegistration`, so a new address and a known one take
  the same time too. `App\Services\Lms\Registrar` holds the rules: a new address
  gets an account through `AccountCreator` and counts as confirmed; an existing
  student must confirm from `/student/registrations` before anything goes on their
  record, and staff cannot approve it for them (X5); a staff or client address gets
  nothing sent. Messages to an existing account repeat nothing the sender typed.
  Forms are `noindex` and out of the sitemap, and an LMS only course may have one.
- **A student's notes are theirs alone.** `LessonNote` is never shown to staff, in any
  report or export, and "view as" says they are private instead of showing them.
- **Time on a lesson is an indication, never a measurement.** The page sends beats
  only while it is visible; `LessonVisit` caps each beat and the total, and it is
  shown in coarse buckets. Nothing grades, warns or ranks on it.
- **Reminders go once.** `App\Services\Lms\Reminders` claims each one in
  `reminders_sent` before sending, so overlapping or repeated runs of `lms:remind`
  cannot double up. Every kind a student can switch off is in `Reminders::KINDS`;
  add a new kind there, and to what the Reminders page says.
- **Signals are prompts, not verdicts.** `Performance` and the batch view flag who may
  be slipping; a warning is still a person's decision. Two missed classes tell the
  trainer, never issue a notice.
- **Questions on a lesson are seen by the asker's batch only**, as first name and
  initial (`LessonDoubt::visibleOn`). Answering takes `lms.doubts`.
- **A hidden name keeps its place.** `Leaderboard` shows a student who hid their name
  as "A student" in their real rank, so hiding moves nobody; they see themselves and
  staff pass `revealNames`.
- **"Online now" is `last_seen_at` in the last five minutes**, written by
  `RecordLastSeen` at most once a minute and never while viewing as somebody. It is
  not the chat presence channel, which only knows who has a chat open.
- **Warnings climb one rung at a time** — notice, warning, escalation — and the
  student is told every time. The private note is for us and never reaches them.
- `/verify` answers identically for a code that never existed and one that was
  mistyped, and shows nothing about the holder beyond what confirms the document.

## The API for the apps

`routes/api.php`, `App\Http\Controllers\Api\V1`, `docs/API.md`, `docs/api/openapi.yaml`.

- **The document is the contract (X25).** `docs/api/openapi.yaml` lists every route under
  `/api/v1`, and `tests/Feature/Api/ContractTest.php` holds the server to it on the demo
  data: a route missing from it, a documented route that does not exist, or an answer
  with a field it does not list, without one it does, or of the wrong type, fails. Change
  the document in the same commit as the endpoint. Within v1 fields are only added; a
  breaking change is `/api/v2` beside v1.
- **The app is held to the web's rules.** `password.owned` answers `423`
  (`password_change_required`) to everything but `/me`, `/auth/password` and signing
  out until a temporary password is replaced, and the two-step step takes only a
  challenge token: Sanctum's `can()` is true of a full token for every ability. Files
  (documents, invoices, receipts, handouts, chat media) are served to the app by the
  portal's own actions on token routes, so a URL in an answer always opens with the
  token. Every `GET` carries an ETag (`cache.headers:private;etag`).
- **Devices are tokens.** One per `device_identifier`; `/auth/refresh` swaps it, `/devices`
  lists and signs out. Push tokens are kept now and used once the apps and a Firebase
  project exist.

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
  badge for every client on the platform. The one exception is the owner, who is
  an `admin` participant of every batch group (`Rooms::syncBatch`), joined muted
  and read up to the moment they joined; a muted room shows no unread count.
- **Running a group is `Conversation::canBeModeratedBy()`**: the owner, the batch's
  trainer and staff holding `chat.reply`. Pinning, announcement mode, keeping a
  student from posting (`silenced_until`, not `is_muted`, which is somebody muting
  the room for themselves) and the reports queue all ask it, through
  `App\Services\Chat\Moderation`, which audits each one. Read the trainer with
  `trainerId()`: a constrained eager load of `batch` drops `trainer_id`, and until
  November 2026 that made the trainer a stranger in their own group.
- **A report takes nothing down.** Members report; a person removes, stops the
  sender posting, or dismisses. Nothing reads messages to decide anything (owner
  decision, 2026-11-10).
- **The poll carries everything the socket does**: new messages, which messages were
  removed since `since` (the server's `now` from the previous poll), and the room's
  settings with this viewer's `blockedReason`. Until November 2026 a removed message
  stayed on screen in a thread without a socket until it was reloaded.
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

## Security headers

`App\Http\Middleware\SecurityHeaders`, `App\Support\Security\ContentSecurityPolicy`.

- **Every page carries a content security policy (Step I).** Scripts come from this site,
  from a per-request nonce on the two inline ones (`Vite::cspNonce()`: the theme script
  and `@routes`), and from Razorpay and Cloudflare Turnstile only; frames are lesson
  videos, the checkout and the human check. A new third party script, frame or
  connection is added to the policy in the same change, or it is blocked. Styles may be
  inline. Every violation is posted to `/csp-reports` and reaches the log and Sentry, so
  a blocked payment window shows up the first time; `CSP_REPORT_ONLY=true` stops the
  blocking at once if it ever must. PDFs and downloads carry no policy.
- Every response also says `nosniff`, a short referrer, no framing by another site, and
  camera and microphone for this site only; production remembers HTTPS.

## Monitoring

`App\Support\Monitoring`, `config/sentry.php`, `resources/js/support/errorReports.js`.

- **A fault goes to Sentry; the person never does (X23).** `config/sentry.php` fixes in
  code, not `.env`, that no request body, cookie, IP or SQL value is sent and that
  tracing is off. `ErrorReports::beforeSend()` takes out the query string, every header
  but the browser's, route parameters that are not plain numbers (an unsubscribe
  address, a reset token), quoted values in driver messages, the arguments in each stack
  frame, and addresses, long numbers and tokens anywhere. Production connections also
  mask bindings in a failed query's own message. Nothing is sent without
  `SENTRY_LARAVEL_DSN`.
- **A fault in a browser reaches Sentry through our server.** `errorReports.js` posts Vue
  errors, page errors and rejected promises from this site's own scripts to
  `/client-errors` (a few per page load, each once; network failures and extensions
  left out), which logs them and passes them on, capped per address and per day. The
  page carries no Sentry script and no key.
- **`/health` is for UptimeRobot.** It sits outside every middleware group, so it needs no
  session, and answers 503 naming what failed: the database, the scheduler, the queue
  or storage. The scheduler touches `storage/framework/scheduler-heartbeat` every
  minute; the dashboard says when it stopped, and Settings, Providers shows the same
  checks.

## Migration from v2

`php artisan legacy:import` and `legacy:reconcile`, `App\Services\Legacy`, tests in
`tests/Feature/Legacy` against `tests/Support/LegacyDatabase.php`. See
`docs/MIGRATION_PLAN.md` (§3 and §9).

- **Real data never reaches the repository or this codebase's tests.** The importer is
  tested only against made up rows shaped like v2; the owner runs it on a copy of the
  production dump and shares only the report, which holds counts and source ids.
- **A step reads only the `legacy` connection and records every row in `legacy_id_map`,**
  so a re-run updates instead of duplicating. A dry run is one transaction rolled back at
  the end; a real run commits step by step. Nothing is sent while it runs.
- **A password is set only when an account is created,** and generation 1 accounts (whose
  hashes the old repository published) get a random one. A real run refuses to guess which
  accounts those are.
- **Anything printed keeps working:** certificate numbers, v2 QR tokens (`legacy_token`),
  Razorpay ids, login emails, old addresses (`redirects`).
- **`legacy:reconcile` must pass before cutover.** Money is compared to the paisa.

## Not yet built

Every phase in the plan has landed; see the "what landed differently" notes under
Phases 5 to 9 in `docs/PROJECT_PLAN.md`. Navigation entries that render as "Soon" are
deliberate placeholders, wired but not yet routed. Instalments, calendar invitations,
message search, a visual filter builder and a report designer are named in the plan
and are not built. Waiting on something outside the code: push notifications to a phone
(the apps and a Firebase project, X25), the live proctoring camera with guardian consent
and the webcam exemption (a video relay, X4 and X12), and a mail provider if the plan's
limit is ever not enough (X15). Offsite backups are not built by decision (X24).

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

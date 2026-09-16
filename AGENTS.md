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

## Not yet built

Phases 3 through 9 in the plan. Navigation entries that render as "Soon" are
deliberate placeholders, wired but not yet routed.

There are no JavaScript tests yet. Client only logic is currently verified by
driving a real browser. Two bugs in Phase 1 were only visible that way, so check
behaviour in a browser before calling a front end change done.

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

## Not yet built

Phases 1 through 9 in the plan. Navigation entries that render as "Soon" are
deliberate placeholders, wired but not yet routed.

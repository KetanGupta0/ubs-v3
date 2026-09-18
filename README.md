# Unboundbyte Solutions — Platform

Web platform for Unboundbyte Solutions Private Limited, covering both business lines from
one codebase:

1. **Software development and maintenance** — client acquisition, delivery tracking,
   billing and support.
2. **Live training for college students** — internships that meet a curriculum
   requirement, longer courses, and a full learning management system behind both.

## Audiences

- **Public visitors** browse an animated solutions catalogue and course listings, and
  raise enquiries.
- **Clients** get a private dashboard for proposals, projects, documents, payments,
  maintenance and chat. Accounts are created by an admin only.
- **Students and interns** get a learning management system with live classes,
  attendance, quizzes, assignments, certificates and batch chat. They can self register,
  or arrive as a batch sent by their college.
- **Admins** run both sides from one control panel.

All three sign in through a single login form, with password, one time password, or
Google, and optional two factor authentication.

## Stack

Laravel 13, Inertia.js 3, Vue 3, Vite 8, Tailwind CSS 4, MySQL 8, Redis, Laravel Reverb
for realtime, Sanctum for the mobile APIs.

## Documentation

| Document | Contents |
| --- | --- |
| [`docs/PROJECT_PLAN.md`](docs/PROJECT_PLAN.md) | Phase by phase build plan, brand identity, technology decisions, risks |
| [`docs/DATA_MODEL.md`](docs/DATA_MODEL.md) | Indicative database schema across all modules |

## Running it

```sh
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed --class=DemoTableSeeder
npm run dev        # and, in another shell:
php artisan serve
php artisan reverb:start   # and in a third, for live chat
```

Then open `/` for the site and `/design` for the live component gallery.

## Demo accounts

Seeded outside production only. The password for all three is `Password123!`.

| Role | Email |
| --- | --- |
| Admin | `admin@unboundbyte.test` |
| Client | `client@unboundbyte.test` |
| Student | `student@unboundbyte.test` |

Mail and SMS both write to `storage/logs/laravel.log` in development, so a one
time code can be read straight out of the log.

## Status

**Phases 0 to 7 are complete.**

Phase 0 built the foundation: brand identity and logo set, design tokens with
full light and dark themes, a component library, the shared server driven table
every dashboard list uses, the public and authenticated app shells including the
phone tab bar, and continuous integration.

Phase 1 built identity and access: one sign in form with five ways in, optional
two factor authentication, password reset, email and mobile verification, session
and device management, an authentication audit log, and the versioned mobile API.

Phase 2 built the public website: a searchable, filterable catalogue of fifteen
products, service and training pages, supporting and legal pages, an enquiry
system that routes to the leads inbox, and the metadata and sitemap search
engines need.

Phase 3 built the admin core: an enquiries inbox that converts an enquiry into an
account, client and student account creation with credentials delivered by email
and SMS and a forced password change before anything opens, colleges and their
memorandums, catalogue management for everything the public site renders, staff
accounts with real permissions, company and message settings, and an audit log of
every change with secrets redacted.

Phase 4 built the client module: projects with milestones and a timeline, proposals
and quotations a client accepts or declines on the record, a versioned document
repository, maintenance contracts with a real SLA clock, payments through
Razorpay with GST compliant invoices and receipts, a full transactions ledger,
subscriptions with renewal reminders, API keys with quotas, and the mobile API
for all of it.

Phase 5 built the learning management system: courses broken into modules and
lessons with four independent locks on each lesson, live classes on Google Meet
with a join window and a register that awards its points as it is marked,
quizzes timed on the server clock with written answers marked by a person,
assignments and projects with a single marking queue across every course, a
result card that weighs quizzes, assignments and attendance into one figure and
shows the weights, a batch leaderboard, certificates and the four internship
documents with a public verification page, an escalating private warning ladder,
colleges with bulk enrolment and a coordinator's own view, and the mobile API for
all of it.

Phase 6 built realtime chat: one thread per client and per project, one group
per batch, text, photos and voice notes and nothing else, waveforms recorded in
the browser, delivered and read receipts, typing indicators, presence, unread
counts, reply quoting and a shared media gallery — over Laravel Reverb, with a
polling fallback so a bad network costs a few seconds rather than the feature.

Phase 7 built reporting, search and exports: one search box that answers with
whatever the person in front of it is allowed to find, saved views that keep a
filter set and can be shared with the team, and a library of seven reports —
revenue, receivables, project health, batch performance, attendance, the
enrolment funnel and certificates issued — each with headline figures, charts
whose palette was checked rather than chosen, the rows behind them, downloads in
three formats, a print stylesheet, and the option to have it arrive by email on
a cadence.

Phases 8 and 9 are described in the plan. Phase 8 is the mobile API.

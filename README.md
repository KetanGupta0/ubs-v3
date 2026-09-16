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

**Phases 0 to 3 are complete.**

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

Phases 4 through 9 are described in the plan. Phase 4 is the client module.

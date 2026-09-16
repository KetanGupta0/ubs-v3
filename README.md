# Unboundbyte Solutions — Platform

Web platform for Unboundbyte Solutions Private Limited, covering both business lines from
one codebase:

1. **Software development and maintenance** — client acquisition, delivery tracking,
   billing and support.
2. **Live training** — course sales, a full learning management system, and live classes.

## Audiences

- **Public visitors** browse an animated solutions catalogue and course listings, and
  raise enquiries.
- **Clients** get a private dashboard for proposals, projects, documents, payments,
  maintenance and chat. Accounts are created by an admin only.
- **Students** get a learning management system with live classes, attendance, quizzes,
  assignments, certificates and batch chat. They can self register.
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

## Status

**Phase 0 is complete.** The foundation is in place: brand identity and logo set,
design tokens with full light and dark themes, a component library, the shared
server driven table that every dashboard list will use, the public and
authenticated app shells including the phone tab bar, and continuous integration.

Phases 1 through 9 are described in the plan. Phase 1 is identity and access.

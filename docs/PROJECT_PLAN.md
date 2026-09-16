# Unboundbyte Solutions Private Limited — Platform Plan

**Document owner:** engineering
**Status:** approved; Phases 0 and 1 delivered
**Last updated:** 2026-09-15

---

## 1. What we are building

One Laravel application that serves three audiences from a single codebase and a single
login form.

| Audience | What they get |
| --- | --- |
| Public visitor | Marketing website, animated solution catalogue, course listings, enquiry forms |
| Client | Private dashboard for projects, proposals, documents, payments, maintenance, chat |
| Student | Full LMS with live classes, attendance, quizzes, certificates, batch chat |
| Admin | Control panel over both business lines |

The two revenue lines are **(1) software development and maintenance** and **(2) live
training**. The platform must make both discoverable to strangers and manageable for
existing customers.

### Non negotiable qualities

- The website itself is the strongest sales proof. Visual quality is a feature, not polish.
- Every dashboard needs real search, sorting, filtering, and exportable reports.
- Fully responsive. On phones the app must feel like a native app, not a shrunk website.
- Every panel gets a matching REST API from day one, so the three mobile apps planned
  for later do not require a rewrite.

---

## 2. Technology decisions

| Layer | Choice | Version |
| --- | --- | --- |
| Backend | Laravel | 13.x |
| Bridge | Inertia.js | 3.x |
| Frontend | Vue 3 (Composition API, `<script setup>`) | 3.5.x |
| Build | Vite | 8.x |
| Styling | Tailwind CSS | 4.x |
| Database | MySQL | 8.0+ |
| Realtime | Laravel Reverb (WebSockets) | bundled |
| Auth tokens | Laravel Sanctum (mobile + SPA) | bundled |
| Social login | Laravel Socialite (Google) | 5.x |
| Queues / cache | Redis | 7.x |
| Search | MySQL full text first, Meilisearch only if volume demands it | — |
| Animation | Motion One plus CSS view transitions, GSAP only where needed | — |
| PDF | DomPDF for invoices, certificates, receipts | — |
| Testing | Pest | 4.x |

### Why this stack fits the brief

Inertia gives server side routing and authorisation with a Vue single page feel, which is
the fastest way to build three deeply different dashboards without maintaining a separate
API client for the web. The same controllers expose JSON for the mobile apps through a
thin `/api/v1` layer that reuses the identical form requests and policies.

---

## 3. Brand identity

Decided up front because every later phase depends on it.

### Logo concept

The name means "byte without bounds". The mark is a **square bracket pair that has been
broken open, with a byte block escaping through the gap**, resolving into a `U`. It reads
as: code (brackets), data (the block), and freedom (the break). It works at 16px favicon
size and as a large hero mark, in one colour, and in reverse on dark.

Deliverables: full horizontal lockup, stacked lockup, icon only, monochrome, favicon set,
social share image. All hand authored SVG, no raster dependency.

### Palette

| Token | Hex | Use |
| --- | --- | --- |
| `--ink` | `#0B1020` | Primary dark surface and headings |
| `--brand-600` | `#4F46E5` | Primary indigo, buttons and links |
| `--brand-400` | `#818CF8` | Hover, gradients |
| `--accent-500` | `#06B6D4` | Electric cyan, highlights and data |
| `--signal-500` | `#22C55E` | Success, paid, present |
| `--warn-500` | `#F59E0B` | Warnings, due, pending |
| `--danger-500` | `#EF4444` | Errors, overdue, absent |
| Neutrals | slate ramp | Text, borders, surfaces |

Indigo to cyan reads as technical and trustworthy, avoids the saturated blue every
agency uses, and gives enough contrast for charts and status chips. Full light and dark
themes ship together, not dark as an afterthought.

### Typography

- Headings: **Sora** — geometric, confident, distinctive without being trendy.
- Body and UI: **Inter** — the most legible UI face at small sizes.
- Code and data: **JetBrains Mono** — for API keys, invoice numbers, tabular figures.

Self hosted via `@fontsource`, subset to Latin, so no layout shift and no third party
request on first paint.

---

## 4. Phase plan

Nine build phases plus a hosting phase. Each phase ends in something demonstrable.

### Phase 0 — Foundation and design system

Set up the skeleton everything else is built on.

- Laravel + Inertia + Vue + Vite + Tailwind wiring, MySQL connection, Redis, queue worker.
- Brand tokens as CSS custom properties, light and dark themes.
- Logo SVG set and favicon generation.
- UI kit: button, input, select, combobox, date picker, file drop, modal, drawer, toast,
  tooltip, tabs, card, badge, avatar, empty state, skeleton loader.
- Data table primitive with server side search, sort, filter, pagination, column toggle,
  saved views, and CSV/XLSX/PDF export. Every dashboard list in later phases reuses this
  one component, which is why it is built first.
- Three app shells: public, and an authenticated shell that adapts per role.
- Mobile shell: bottom tab bar, swipeable drawers, pull to refresh, safe area insets,
  installable PWA manifest, native style page transitions.
- CI: lint, static analysis, test run on every push.

**Exit:** a styled empty app with a working component gallery at `/design`.

**Delivered.** The logo set (mark, horizontal, stacked, monochrome, favicon and
the PWA icon sizes), the token layer with light and dark defined together, the
component library, the shared table with exports, both app shells, the phone tab
bar and More sheet, the installable manifest, and a CI pipeline that runs style,
tests against MySQL and a production asset build. The gallery at `/design` is a
working page rather than a mockup: its table is a real server driven table, so a
regression in search, sorting, filtering or export fails there first.

### Phase 1 — Identity and access

One login form, many ways in. Built early because both business modules depend on it.

- `users` table with a `role` column (`admin`, `client`, `student`) plus granular
  permissions for future sub admins.
- Unified login accepting **either email or mobile number** with password.
- OTP login over email and SMS, rate limited, single use, expiring.
- Google sign in through Socialite, with account linking rules for existing emails.
- Optional two factor authentication (TOTP app codes) with recovery codes.
- Password reset, email verification, mobile verification.
- Session management: active device list, revoke session, forced logout.
- Sanctum personal access tokens scoped per device, ready for the mobile apps.
- Audit log of every authentication event.
- Role based redirect after login into the correct dashboard.

**Exit:** all three roles can log in five different ways and land in the right place.

**Delivered.** One users table carrying role and status, with profiles and a
granular permission list for sub admins. A single sign in form accepting an email
address or a mobile number in any of the forms people type it. One time codes
over email and SMS, stored hashed, single use, expiring, capped at five guesses
and rate limited per destination. Google sign in that refuses an unverified
Google address and only ever auto creates a student. Optional authenticator app
two factor with single use recovery codes, enforced on every route in including
Google and one time codes. Password reset, the forced first password change for
admin created accounts, email and mobile verification, a session and device list
with revocation, and an audit log that masks the identifiers it records. A
versioned mobile API issuing per device Sanctum tokens, with a scoped short lived
token for the two factor step and push token registration.

### Phase 2 — Public marketing website

The part that converts strangers. Highest visual investment.

- **Home:** animated hero, credibility strip, two clear paths (hire us / learn with us),
  capability grid, process timeline, technology marquee, testimonials scaffold, CTA.
- **Solutions catalogue:** the centrepiece, since there are no live projects to show yet.
  A searchable, filterable grid of the software products we can build, filterable by
  industry, category, platform, and technology. Each card animates on hover into a
  preview. Each product gets its own page with an animated feature tour, module
  breakdown, illustrative mock screens, indicative timeline, and indicative pricing band.
  Every product page carries an enquiry form pre tagged with that product.
- **Services:** development, upgrade and modernisation of existing software, maintenance
  and AMC, each with scope, deliverables and engagement models.
- **Training:** public course and programme listings, syllabus, schedule, fees, batch
  dates, and a register call to action. Courses marked LMS only are hidden here.
- Supporting pages: about, process, technology, FAQ, careers stub, contact, legal pages.
- Contact and enquiry forms land in the admin leads inbox with source attribution, plus
  email and SMS alerts to admin and an auto acknowledgement to the sender.
- SEO: server rendered meta, JSON-LD schema, sitemap, robots, per page OG images,
  analytics, Core Web Vitals budget.

**Exit:** a public site good enough that a visitor believes the work here is serious.

### Phase 3 — Admin core

The control plane both later modules plug into.

- Admin shell, global search, command palette, notification centre.
- Dashboard: revenue, pipeline, active projects, active batches, dues, enrolments.
- **Client account creation.** Admin only, no self signup. On creation the system
  generates credentials and sends a welcome message with them to the registered email
  and mobile, forces a password change on first login, and logs the delivery.
- Student account creation from the admin side, alongside student self registration.
- Leads inbox: assign, status, notes, convert lead to client.
- Catalogue management for everything the public site renders.
- Staff users, roles, permissions.
- Settings: company profile, tax details, invoice numbering, mail and SMS providers,
  payment gateway keys, templates for every automated message.
- System audit log.

**Exit:** admin can run the public site content and create both kinds of accounts.

### Phase 4 — Client module

Everything a paying client sees. Nothing more.

- **Projects.** One client may hold many. Each has scope, team, phase, and a live
  development tracker with milestones, progress percentage, and a visual timeline.
- **Proposals and quotations** with line items, versions, validity, and online accept or
  reject with a recorded timestamp.
- **Documents** repository, folder tree, versioning, preview, download, access control.
- **Maintenance:** AMC contract, covered scope, ticket raising, SLA clock, ticket history.
- **Payments:** admin raises a payment request for a service fee, a development milestone,
  or a renewal. Client pays online through the gateway. The system issues a numbered
  GST compliant invoice and a receipt.
- **Transactions history:** full ledger, filterable, printable, downloadable as PDF or
  spreadsheet.
- **Subscriptions and renewals** with expiry reminders at configurable intervals.
- **API key purchasing**, for products that need keys. Purchase, issue, rotate, revoke,
  usage quota and usage graph.
- Notifications, plus client side reports on project progress, spend, and tickets.

**Exit:** a client can be onboarded, quoted, tracked, billed, and supported end to end.

### Phase 5 — Learning management system

The training business, in full.

- **Courses and programmes** with modules and lessons. Visibility flag: publicly listed,
  or visible only inside the LMS.
- **Batches** with schedule, capacity, and assigned trainer.
- **Enrolment and course purchase**, coupons, instalments, invoices.
- **Content locking:** drip by date, unlock by prerequisite, unlock by payment, unlock by
  quiz score. Locked items are visible but sealed, to drive completion.
- **Live classes on Google Meet.** Scheduled sessions carry the Meet link, calendar
  invites go to enrolled students, and the join button opens at class time.
- **Attendance** captured per live session, with per student percentage and shortfall
  alerts.
- **Study materials** per lesson, with download control.
- **Quizzes:** question bank, multiple types, timed attempts, auto grading, review.
- **Projects and assignments:** brief, submission, deadline, trainer evaluation, feedback.
- **Results** consolidating quizzes, assignments and attendance into a report card.
- **Leaderboard and activity tracking**, points for attendance, submissions and scores.
- **Certificates** auto issued on completion, PDF, with a public verification URL.
- **Announcements and notifications**, course wide or batch wide.
- **Targeted warning system.** A trainer flags a student who is inattentive in class. The
  student receives a private, escalating warning: notice, then warning, then a parent or
  guardian alert. Every flag is logged with a reason, and the trainer sees the history.
- **Transactions history**, printable and downloadable, same as the client side.

**Exit:** a batch can be run from enrolment to certificate without leaving the platform.

### Phase 6 — Realtime chat

- WhatsApp style threads. **Text, image and audio only**, by explicit requirement, so no
  arbitrary file uploads and a smaller attack surface.
- In browser audio recording with waveform preview.
- Client threads: per client, optionally scoped per project, talking to admin.
- Student threads: one group per batch, so each course or programme has its own room.
- Delivered and read receipts, typing indicators, presence, unread counts, reply quoting,
  media gallery per thread.
- Powered by Laravel Reverb, with a queued fallback to polling on hostile networks.
- Media stored privately with signed, expiring URLs.

**Exit:** live conversation between admin, clients and batches.

### Phase 7 — Reporting, search and exports

- Global search across projects, clients, students, invoices and courses.
- Advanced filter builder with saved and shared views.
- Report library per role: revenue, receivables, project health, batch performance,
  attendance, enrolment funnel, certificate issuance.
- Charts with accessible colours in both themes.
- Every report and list exports to CSV, XLSX and PDF, and has a clean print stylesheet.
- Scheduled reports emailed on a cadence.

**Exit:** the numbers needed to run the business are one click away.

### Phase 8 — Mobile API

API work happens inside each phase, not only here. This phase hardens and documents it.

- Versioned `/api/v1` covering admin, client and student surfaces.
- Sanctum device tokens, refresh and revoke, biometric friendly re auth.
- Device registration and push notification delivery through Firebase Cloud Messaging,
  with the same notification payloads the web uses.
- Offline friendly responses: cursor pagination, ETags, delta sync endpoints.
- OpenAPI specification, generated Postman collection, and a sandbox environment.
- Contract tests so a future app release cannot be broken silently.

**Exit:** three mobile apps could start development against a stable documented API.

### Phase 9 — Hardening and hosting

- Authorisation policies on every model, verified by tests.
- Rate limiting, input validation, file scanning, signed URLs, CSP headers.
- Security review: OWASP top ten pass, dependency audit, secrets handling.
- Performance: query budgets, eager loading audit, indexes, cache layers, image pipeline.
- Accessibility pass to WCAG 2.2 AA.
- Seed data so every screen demos well without real customers.
- Backups: nightly database dump plus offsite media sync, with a tested restore.
- Deployment: Nginx, PHP-FPM, Supervisor for queues and Reverb, Redis, cron scheduler,
  TLS, zero downtime deploys from CI.
- Monitoring: uptime, error tracking, log aggregation, queue depth alerts.

**Exit:** live on the production domain.

---

## 5. Sequencing

Phases 0 and 1 are strictly first. After that:

```
Phase 0 ─ Phase 1 ─┬─ Phase 2 (public site)
                   ├─ Phase 3 (admin core) ─┬─ Phase 4 (client)
                   │                        └─ Phase 5 (LMS)
                   └─ Phase 6 (chat, needs 4 and 5 to exist)
Phase 7, 8 ─ across all modules
Phase 9 ─ last
```

Phase 2 can run in parallel with Phase 3 because they share only the design system.
Phase 4 and Phase 5 are independent of each other once Phase 3 lands.

## 6. Delivery order recommendation

If the business wants value early rather than everything at once, ship in this order:

1. Phases 0, 1, 2 and the leads inbox from Phase 3. This puts a selling website online.
2. Phase 3 and Phase 5. Training runs on the platform and starts collecting fees.
3. Phase 4. Client delivery moves onto the platform.
4. Phases 6, 7, 8, 9.

## 7. Open decisions

Sensible defaults are chosen so work is not blocked. Say the word to change any of them.

| Decision | Default chosen | Note |
| --- | --- | --- |
| Payment gateway | Razorpay | Best India coverage, UPI, subscriptions, easy refunds |
| SMS provider | MSG91 | India DLT compliant, OTP templates, good delivery |
| Transactional email | Amazon SES, Resend as fallback | Cost and deliverability |
| Media storage | Amazon S3 compatible, local disk in development | Signed URLs |
| Hosting | Single VPS to start, container ready for later scale | Cheapest path that stays upgradable |
| Domain | to be supplied | Needed for TLS and mail domain authentication |
| GST invoicing | enabled, GSTIN configurable | Required for Indian B2B clients |
| Currency | INR primary, USD display optional | — |

## 8. Risks and how they are handled

| Risk | Mitigation |
| --- | --- |
| No live projects to showcase | Phase 2 catalogue is built as the showcase, with illustrative mock product pages rather than fake client logos |
| Scope is very large | Strict phase gates, every phase independently shippable |
| Google Meet has no official auto attendance | Attendance is marked in app by the trainer, with a one tap roster; optional Google Calendar API integration for link generation |
| Chat media abuse | Image and audio only, size caps, private storage, signed URLs, per user rate limits |
| Credentials sent over SMS and email | One time password, forced rotation on first login, delivery logged, never stored in plain text |
| Two business lines diluting the brand | Public site splits the two paths above the fold instead of blending them |

---

## 9. What happens next

On approval, Phase 0 begins: repository scaffold, brand tokens, the logo set, and the
component gallery. Each phase lands on the feature branch with its own commits and a
short written summary of what is demonstrable.

# Migrating the live site (ubs-v2) into this platform (ubs-v3)

Status: **plan only**. Nothing here is built yet. Read with `docs/PROJECT_PLAN.md`,
`docs/EXPANSION_PLAN.md` (new modules and the order of work) and `CLAUDE.md`; every rule in `CLAUDE.md` still applies to imported data.

Goal: bring every real record from the live ubs-v2 site into v3 without losing,
duplicating or silently changing anything, and close the gaps so the v3 admin panel
manages the LMS and the public site's content completely before v2 is switched off.

---

## 0. Do this first: the ubs-v2 repository is exposing personal data

Found while reading the repository (`github.com/KetanGupta0/ubs-v2`, which answers
publicly on the GitHub API):

| What | Where |
|---|---|
| ~108 real students: name, email, mobile, city, state, pincode, qualification, **bcrypt password hash** | `database/migrations/2026_06_14_100003_seed_legacy_users.php` |
| 155 enrolments, 139 payments, 157 certificates, 43 reviews tied to those emails | `database/migrations/2026_06_14_1000{04..10}_*.php` |
| The admin account's password is set to its own email address | `database/seeders/AdminSeeder.php` line 19 |

Actions, in this order, none of which need code:

1. **Make ubs-v2 private today.** (GitHub → Settings → Danger zone → Change visibility.)
2. **Change the live v2 admin password**, and any other admin whose password was ever
   seeded, then sign every session out.
3. Rewrite the repository without the `seed_legacy_*` files (it has a single commit, so a
   history is two commits, so a clean history is simple), then ask GitHub support to purge cached views. Anyone who
   cloned or forked it already has a copy; treat the data as exposed.
4. Decide with whoever handles compliance whether this needs notice under the DPDP Act,
   2023. Not a technical call, but it should be made deliberately and written down.
5. Because the hashes were public, **every generation 1 (v1) account gets a new random
   password on import** that nobody sees, plus `must_change_password = true`. Access comes
   back when an admin sends credentials (single or bulk) or the student uses forgot
   password. Generation 2 accounts keep their hashes. Decided 2026-11-10; details in
   `docs/EXPANSION_PLAN.md` §1.

From here on, real data never enters a repository again: not in seeders, migrations,
fixtures or zip files. The importer in this plan reads a database dump that lives only on
the server.

---

## 1. What is being migrated

ubs-v2 is Laravel 13 + Jetstream + Inertia 2 + Vue 3, MySQL. It is itself a migration
target: an older system (`ubs_old`, v1) was folded into it in June 2026 through the
`seed_legacy_*` migrations. So the live v2 database holds two generations of data:

- **v1 legacy rows**, tagged by those migrations (e.g. `certificates.is_legacy = 1`).
- **Native v2 rows** created on the live site since then: Razorpay enrolments, quiz
  attempts, assignment submissions, tickets, notices, reviews and contact queries.

The only reliable source is **a dump of the production v2 database plus its `storage/`
directory**. The seed files in the repository are a snapshot from June and must not be used
as the source.

Known volumes from the seed files (production will be higher):

| Data | Rows (June snapshot) |
|---|---|
| Students | ~113 (108 inserted, 6 skipped as already present) |
| Programs / batches + live classes | 4 legacy programs, ~86 batch and class rows |
| Enrolments | 155 |
| Payments | 139 |
| Attendance | 4 seed files |
| Certificates | 157, all legacy |
| Reviews | 43 |

This is small, so the importer can afford to verify every row individually rather
than sampling.

The `new_update8.zip` in the v2 repository is an older deploy bundle (it predates the
legacy import and has no `QuizAttemptOverride`). It is not a data source.

---

## 2. Principles

1. **v2 is never written to.** The importer reads a *restored copy* of the production dump
   through a separate `legacy` database connection, never the live database.
2. **Re-runnable and idempotent.** Every imported row is recorded in a `legacy_id_map`
   (`source_table`, `source_id`, `target_type`, `target_id`, `source_checksum`). A second
   run updates in place, so it never duplicates. A final run happens at cutover against a fresh dump.
3. **One import path.** Rows are written through the same services the app uses where those
   services enforce rules (`Money::toPaise`, `Identifier::normalise`, `Auditor`). Where a
   service would trigger side effects (mail, points, notifications), the importer writes
   directly and records that it did.
4. **No invented history.** Nothing is inferred that v2 did not record: no absences, no
   back-dated GST invoices, no points for activity that earned none. If v2 does not know
   something, v3 shows it as unknown.
5. **Anything printed on paper keeps working.** Certificate numbers, certificate QR links,
   Razorpay payment ids and student login emails survive exactly.
6. **Every run ends in a reconciliation report.** Counts, sums in paise and per-row checks,
   source against target. A run with an unexplained difference is a failed run.
7. **Secrets are not data.** The v2 `settings` table stores SMTP, Razorpay and Google OAuth
   credentials. They move to `.env` by hand and are never imported, because of the
   provider-credentials rule in `CLAUDE.md`.

---

## 3. How the import works

```
production v2 ──mysqldump──▶ restored copy (legacy connection, read only user)
                                   │
       php artisan legacy:import --dry-run      ← every step, in a transaction, rolled back
       php artisan legacy:import                ← for real, step by step
       php artisan legacy:reconcile             ← report: counts, paise sums, row checks
                                   │
production v2 storage/ ──rsync──▶ v3 disks, sha256 verified per file
```

- `legacy:import --only=users,courses,...` runs a single step; steps run in dependency order
  (users → courses → batches → enrolments → sessions → attendance → content → assessments
  → certificates → money → support → reviews → leads → CMS).
- Each step is one class under `App\Services\Legacy\Steps`, with its own test against a
  fixture database built from **fake** data shaped like v2.
- `--dry-run` executes everything inside a transaction and rolls back, so the report shows
  exactly what a real run would do.
- No mail, SMS, broadcast or queued job fires during an import. The importer runs with
  notifications faked and `Activity::did()` bypassed, so the logs say why.
- Files: copied under v3's own directory layout, renamed to v3's conventions, checksummed.
  A file v2 references but which is missing on disk is listed in the report, never silently
  dropped.

---

## 4. Table by table

`v2 → v3`, and what changes on the way.

### People

| v2 | v3 | Notes |
|---|---|---|
| `users` (role student) | `users` role `student` + `user_profiles` | Email lowercased; `phone` → `mobile` in E.164 through `Identifier::normaliseMobile`. A mobile number that is duplicated or malformed is imported as null and listed for follow-up, because `mobile` is unique. `qualification`, `date_of_birth`, `city`, `state`, `pincode` → profile `postal_code`, `country`. `address`/`address_line2`/`bio` have no v3 column → see §5. |
| `users.password` | `users.password` | **Generation 1 (v1):** replaced by a random password, hashed at once and never stored or shown, `must_change_password = true`; nothing is mailed during the import. **Generation 2:** bcrypt hash copied as it is, written with the query builder so the `hashed` cast cannot re-hash it. `has_password = false` (Google only) → `password = null`. |
| `users.google_id` | `social_accounts` (provider `google`) | Only where v2 holds the id; v3's verified email rule still applies at the next sign in. |
| `users.is_blocked`, `block_reason` | `users.status = suspended` + audit entry carrying the reason | |
| `users` 2FA columns, `passkeys` | not migrated | Encrypted with v2's key and a different scheme. Affected users are told to set 2FA up again. |
| `users` role admin | `users` role admin, **not owner** | Each one gets explicit `permission_user` rows chosen by the owner. The v3 owner stays the single `is_owner` account. |
| `users` role client | `users` role client | Only if the live database has any; v2 had no client module. |
| `sessions`, `personal_access_tokens`, `password_reset_tokens` | not migrated | Everybody signs in again on v3. |

### Catalogue and delivery

| v2 | v3 | Notes |
|---|---|---|
| `training_programs` | `courses` | `type` decided per row (the four legacy programs are internships). `price` decimal rupees → paise. `is_public = false` or inactive → `visibility = lms_only`, `is_published = false`, so the course-visibility tests keep covering it. `curriculum` json → `course_modules` + `lessons` where it has structure, otherwise `syllabus`. A live public v2 program that matches a v3 catalogue entry is **mapped to it by an explicit list**, never matched by title. |
| `batches` | `batches` | `code` is required and unique in v3 → generated (`V2-<id>`) and shown to the admin. `max_students` → `capacity`. `seats_taken` recomputed from enrolments. `status` derived from dates. |
| `batch_students` + `enrollments` | `enrollments` | One v3 enrolment per student and course; `batch_id` from v2 `enrollments.batch_id`, falling back to `batch_students`. Status map: `active→active`, `completed→completed` (`completed_at` from the certificate or batch end), `cancelled→dropped`, `blocked→suspended`, `pending_assignment→active` with no batch. `pending_payment` is **not** an enrolment: it becomes an unpaid `payment_request` (or a lead), so nobody gets course access they did not pay for. `amount` and `student_*` snapshot fields stay in the import report. `admin_notes` and `certificate_bypass`/`bypass_reason` → audit log entries. |
| `live_classes` | `live_sessions` | `join_url` → `meet_link`; `is_cancelled` → `status = cancelled`; past ones `completed`. |
| `attendances` | `attendances` | A v2 row means the student joined → `status = present`, `marked_at = joined_at`, `is_manual` kept in `note`. **Absences are not created** for past sessions (see decision D5). |
| `study_materials` (per batch) | `materials` (per course) | Files copied, `mime` re-read from bytes. Materials that were specific to one batch would become visible to every batch of the course → needs `materials.batch_id` (§5). |
| `notices` + `notice_reads` | `announcements` | `target_type all` → audience all, `batch` → that batch. A notice to **one student** → an announcement with `audience = student` and `user_id` set (built 2026-11-10), which only that student sees. Read receipts are dropped. |

### Assessment and credentials

| v2 | v3 | Notes |
|---|---|---|
| `quizzes` (per batch) | `quizzes` (per course) | Needs `quizzes.batch_id` (§5), otherwise one batch's quiz opens for every batch. `passing_score` → `pass_percent`, `max_attempts` → `attempts_allowed`, `due_at` → `closes_at`, `show_answers_after` → `show_answers`. |
| `quiz_questions` | `questions` | `multiple → multi`; `options [{text,is_correct}]` → v3 `options` + `correct`. Type `file` has no v3 equivalent → converted to an assignment, listed in the report. |
| `quiz_attempts` + `quiz_answers` | same | Scores copied, **not regraded**: a student's historical result must not change because the grader did. `is_graded = false` → `needs_review = true`. `expires_at` from `started_at + time limit`. |
| `quiz_attempt_overrides` | new `quiz_attempt_grants` (§5) | |
| `assignments` | `assignments` (`batch_id` already exists) | `instructions` → `brief`; `allowed_types` kept for the upload rule. |
| `assignment_submissions` (every version) | `submissions` | v2 keeps resubmission history (`submission_number`, `is_latest`). The latest becomes the `submissions` row, with `version` set; the earlier ones become `submission_versions` rows, feedback and marks included (built 2026-11-10). Grading, feedback, `graded_by` copied. |
| `certificate_templates` | see §6, LMS gap L6 | `canvas_json` + background are v2's visual designer. |
| `certificates` | `certificates` | `certificate_number` → `number` **unchanged**. New 32-character `verification_code` generated. The 64-character v2 `token` is kept in a new `legacy_token` column (§5) and `/verify/{token}` falls back to it, case-sensitively, because v3 upper-cases codes and the v2 QR codes would otherwise all 404. Before cutover, **every v2 certificate is rendered to PDF on v2 and stored as `pdf_path`**, so the document a student already holds never changes appearance. Revocations and reasons copied. |

### Money

| v2 | v3 | Notes |
|---|---|---|
| `payments` (paid) | `transactions` | Decimal rupees → paise through `Money::toPaise`. `razorpay_order_id/payment_id/signature` → `gateway_*`. Paid → `status = successful`. `method` cash/upi/bank_transfer → `gateway = offline` (as v3's own offline payments are), `method` kept. `recorded_by` → audit entry. Linked to the enrolment's `payment_request`. |
| `payments` (pending/failed/refunded) | `transactions` with that status; refunded → `refunds` row | |
| (none) | `invoices` | **Not created retroactively.** v2 never issued GST invoices; inventing numbered invoices for 2024–25 would disturb the invoice sequence and the filed returns. Students see receipts for imported payments. See decision D6. |

Reconciliation: sum of paid paise per student, per course and per month must equal v2
exactly, and every Razorpay payment id must appear exactly once.

### Support, feedback, enquiries

| v2 | v3 | Notes |
|---|---|---|
| `support_tickets` + `ticket_replies` | depends on decision D2 | v3 tickets belong to clients (`client_id`, contracts, SLA). Student tickets need either a student-capable ticket or an import into chat. `is_internal` replies stay staff-only either way. |
| `reviews` | `course_reviews` (built 2026-11-10) | Imported with status. Only an `approved` review can be promoted to a homepage `testimonial`, by a person, because of the no-fabricated-proof rule. |
| `contact_queries` | `leads` | `status unread→new`, `read→contacted`, `replied→contacted`, `archived→lost`; `admin_note` → `lead_notes`. `reference` generated. |

### Content (CMS)

| v2 | v3 | Notes |
|---|---|---|
| `services` | `services` | Content reviewed by a person, not copied blind: v3 services are richer and already seeded. |
| `faqs` | `faqs` | Merged by question text, duplicates listed. |
| `team_members` | `team_members` (built 2026-11-11) | `type` mentor/team/both → `is_mentor` / `on_about`; `tech_stack` split on commas into `skills`; `avatar_url` downloaded and re-encoded into `photo_path`; `initials` and the gradient columns are dropped (initials are worked out from the name). |
| `custom_pages` | `pages` (built 2026-11-11) | v2 stored HTML; v3 pages are blocks. Each v2 page is converted to Markdown text blocks (HTML to Markdown, so scripts and inline handlers cannot survive), kept as a draft, and published by a person after a look. |
| `page_metas` | `seo` json on the page/route it describes (CMS gap C2) | |
| `settings` (general, appearance) | v3 `settings` | Name, contact details, social links, logo, favicon. **Secrets are excluded** (§2.7). |
| `email_templates` | `message_templates` | Variables renamed to v3's; anything that would mail a plain password (v2's `account_created` template does) is not carried over, because v3 credential delivery works differently. |

---

## 5. Schema changes v3 needs before any import

Each is a normal migration with tests, landing in the LMS/CMS work below.

1. `legacy_id_map` table (the importer's ledger).
2. `certificates.legacy_token` (string 128, unique, nullable) + case-sensitive fallback in
   `VerificationController`, keeping identical answers for unknown codes.
3. `quizzes.batch_id` and `materials.batch_id` (nullable: null = every batch of the course).
4. `quiz_attempt_grants` (quiz, student, extra attempts, reason, granted_by).
5. Submission history: `submission_versions`, or versions inside `submissions.files`.
6. `course_reviews` (student, course, enrolment, rating, title, body, status, reply).
7. Student tickets (decision D2): `support_tickets.client_id` generalised to `requester_id`
   with the SLA fields optional, or nothing if tickets go into chat.
8. `user_profiles.address` (text) for the postal address v2 collected.
9. `enrollments.status` values written down as an enum (`active`, `completed`, `dropped`,
   `suspended`) so the import map has something to aim at.

---

## 6. Gaps to close in v3 first

v2's admin panel can do things v3's cannot yet. v2 cannot be switched off until v3 does
all of them, otherwise the team loses a capability on cutover day.

### LMS management (admin)

| # | Gap | v2 has it as |
|---|---|---|
| L1 | Enrolment management screen: list, filter, move between batches, change status, add manually, record an offline payment | `Admin/Enrollments`, `PaymentController@addManual` |
| L2 | Student profile screen with enrolments, payments, attendance, results, certificates, warnings, block/unblock, reset password, impersonate (audited) | `Admin/Students/Show`, impersonation |
| L3 | Per-batch quizzes and materials (needs §5.3) and a materials library across courses | `Admin/Materials/Index` |
| L4 | Extra quiz attempts for one student | `QuizAttemptOverride` |
| L5 | Assignment resubmission request and version history | `resubmission_*`, `submission_number` |
| L6 | Certificate templates: a template per course with a background image and placed fields, previewed before issue | `CertificateTemplateController` with editor |
| L7 | Live class list across all batches (upcoming, past, cancelled) | `Admin/LiveClasses/Index` |
| L8 | Notices to one student | `notices.target_type = student` |
| L9 | Reviews moderation with reply | `Admin/Reviews` |
| L10 | Public enrolment with Razorpay checkout from a course page, creating the account, enrolment and payment in one flow | `/enroll/{program}` |
| L11 | Toggle "enrolment open" per course without unpublishing it | `is_enrollment_open` |

Already stronger in v3 and needing nothing: content gate, drip, server-side quiz clock,
marking queue, warnings, leaderboard, register, certificate issue rules, verification.

### CMS (admin)

Today v3 edits FAQs, testimonials, services, solutions and courses; every other word on the
public site comes from `database/data/*.php` through the seeder. "Full CMS" means:

| # | Gap |
|---|---|
| C1 | **Pages**: create, edit, publish and unpublish free-standing pages (block based: heading, rich text, image, call to action, FAQ list), each with slug, SEO and an optional menu entry. |
| C2 | **SEO per page**: title, description (158 character cap enforced in the form), canonical, OG image, indexable, for every fixed route as well as CMS pages. `Seo.vue` reads it. |
| C3 | **Home and fixed page sections**: hero, stats, CTA, about, process, technology copy editable from the panel instead of the data files, with the seeder still used for first install. |
| C4 | **Team / mentors** with photo, role, bio, order, active. |
| C5 | **Navigation and footer** menus. |
| C6 | **Legal documents** (`/legal/{document}`) edited with a version history and "last updated" date. |
| C7 | **Media library**: upload once, re-encoded (EXIF stripped, as chat already does), reused across pages, alt text required. |
| C8 | **Site identity**: name, logo, favicon, contact details, social links, address, GSTIN shown on the site. Still no credentials. |
| C9 | **Message templates** editor with preview and a variable list. |
| C10 | **Draft → preview → publish** for every content type, and every change through `Auditor`, so a bad edit can be traced and reverted. |

*Progress:*
- *C1 built 2026-11-11: pages at `/admin/pages`. Each is written as blocks (heading, text in Markdown, picture, call to action, questions), saved as a draft, previewed, then published to `/pages/{slug}`, with its own search title, description and a switch to keep it out of search. HTML typed into text is stripped and unsafe links are refused. New permission `content.manage`. Menu entries are left to C5.*
- *C2 built 2026-11-11: search wording for the thirteen fixed public pages at `/admin/seo` (title, description, share picture, keep out of search), applied by path in `Seo::for()`. Pages written in the panel and catalogue items keep their own search fields. v2's `page_metas` map onto these rows by path.*
- *C4 built 2026-11-11: team and mentors at `/admin/team` (permission `content.manage`): name, role, experience, a short bio, what they teach, LinkedIn, a re-encoded photo, order, shown or hidden, and whether each person appears on the About page, as a mentor on the Training page, or both. Nobody is seeded: each section stays off the site until a real person is added. A replaced or removed photo is deleted from disk.*
- *C5 built 2026-11-11: the header and the two footer columns at `/admin/menus` (`content.manage`), starting with the links the layout used to carry. A link is a path on this site or an `https://` address (opened in a new tab). A path is checked against the site's routes when saved, and a panel page or legal document must be published, so a typo is an error on the form rather than a 404 in every footer. The header holds at most seven links. Published legal documents still close the footer on their own. v2's menu, if it had one in the database, is not carried over: v3's routes differ.*
- *C7 built 2026-11-11: the media library at `/admin/media` (`content.manage`). A picture is uploaded once with a required description, re-encoded (metadata removed, at most 1600 px wide; PNG stays PNG for transparency) and picked from any page's picture block. A picture uploaded inside the page editor joins the library too, but only once the whole page is valid. A picture still shown on a page cannot be deleted, and the list says which pages use it. Team photos and certificate artwork keep their own uploads, because they are processed differently.*
- *C8 built 2026-11-11: a "Public site" tab in Settings (`settings.manage`). The company details already kept for invoices (email, phone, address, CIN, GSTIN) appear on the public site only when switched on, item by item, plus a WhatsApp number and profile links (LinkedIn, Instagram, YouTube, Facebook, X, GitHub, each checked to be on its own network). They show in the footer of every page, on the contact page and in the organisation structured data. The PAN is never shown; a GSTIN contains it, which the switch says. The footer's copyright uses the legal name from settings. Logo and favicon stay design assets in the code, not uploads: they need light and dark variants and a set of icon sizes that an upload box would get wrong.*
- *C6 built 2026-11-11: the privacy policy, terms and refund policy at `/admin/legal`, with their own permission `legal.manage`. Each change is a new version with a note on what changed; a published version is never edited or deleted (the model refuses both), and every earlier one stays readable at `/legal/{document}/versions/{n}`, kept out of search. The page shows the date the version was published, where it used to print today's date. Privacy and terms start from the text that was in the code; the refund policy has no text until somebody publishes one, and until then it 404s and stays out of the footer and sitemap. v2's refund policy comes across in Step C as a draft of version 1, published by a person after a read.*

All of this follows `CLAUDE.md`: shared `DataTable` for lists, `UiFormField` labels,
semantic tokens, permissions per screen (`content.pages`, `content.seo`, …).

---

## 7. Phases

Each lands on its own and is shippable, as in `PROJECT_PLAN.md`.

| Phase | Work | Done when |
|---|---|---|
| **M0 — Contain** | §0 actions. Get a production dump and `storage/` copy onto a private server. List live v2 URLs from its sitemap and analytics. | Repo private, admin password changed, dump restored and readable. |
| **M1 — LMS gaps** | §5 schema changes 2–9, L1–L11. | Every L item works in a browser, tests green. |
| **M2 — CMS** | C1–C10. Current public copy moved from data files into the CMS without visible change. | Marketing team edits a page and its SEO without a deploy. |
| **M3 — Importer** | `legacy_id_map`, `legacy:import`, `legacy:reconcile`, one step class per area, fixture tests on fake data. | Dry run against the restored dump completes with a clean report. |
| **M4 — Rehearsal** | Full import into a staging copy of v3 on MySQL. Admin, a few real students (with consent) and finance check their own data. Fix, re-run from scratch. At least two clean rehearsals in a row. | Reconciliation zero-difference; UAT signed off; timings measured. |
| **M5 — Cutover** | Runbook below. | v3 live on the domain, v2 read only. |
| **M6 — Aftercare** | Redirect and 404 monitoring, password-change follow-up, Razorpay late webhooks, v2 kept read-only for 90 days, then an encrypted archive dump and shutdown. | 90 days with no data issue raised. |

---

## 8. Reconciliation checks

Run after every import, printed and saved:

- Row counts per source table against target, minus documented skips (each skip has a reason).
- Students: every v2 email present once; every mobile present or listed.
- Money: paid total in paise overall, per student, per course, per month; every Razorpay
  payment id once; refunds match.
- Enrolments: per course and per batch counts; nobody gained access without a paid or
  admin-granted enrolment.
- Certificates: every number present; every v2 token resolves via `/verify`; revoked ones
  say withdrawn; every certificate has a PDF.
- Attendance: present count per session.
- Quiz attempts and submissions: count and score sums per quiz/assignment.
- Files: every referenced file exists with a matching sha256; orphans listed.
- Sign in: a sample of legacy students can sign in with their old password and are forced
  to change it (on staging, with consenting accounts).

---

## 9. Cutover runbook (outline)

1. Announce a window to students two days ahead (email + notice on v2).
2. Put v2 in maintenance mode; disable its cron entries in hPanel (shared hosting has no worker to stop). Disable the Razorpay webhook
   to v2, note the time.
3. Final dump + `storage/` sync.
4. `legacy:import` (real) → `legacy:reconcile`, over SSH from the command line (web time limits on shared hosting would cut it off). Stop if any difference is unexplained.
5. Point Razorpay webhooks at v3; fetch any payments captured during the window from the
   Razorpay dashboard and reconcile by hand.
6. Switch DNS / vhost. Enable the v2 → v3 redirect map:
   `/training/{slug}` (by the explicit course map), `/enroll/{slug}` → course page,
   `/privacy-policy` → `/legal/privacy`, `/terms` → `/legal/terms`, `/refund-policy` →
   `/legal/refunds` (publish the refund policy first), `/pages/{slug}` → CMS page,
   `/verify/{token}` served directly, `/dashboard` → role dashboard. All 301s.
7. Smoke test: sign in as each role, open a course, verify three certificates (one revoked),
   make a ₹1 test payment and refund it.
8. Email students: new site, password change on first sign in, how to set up 2FA again.

**Rollback:** until step 6 nothing is visible. After it, v2 is untouched and only in
maintenance mode, so rollback is DNS + webhook back to v2 + maintenance off. Anything done
on v3 in between (payments especially) is exported from v3 and replayed into v2 by hand,
which is why the window should be short and announced.

---

## 10. Decisions needed

**The owner accepted every recommendation below as the default on 2026-11-10.** New
questions are asked before the phase they belong to starts.

| # | Question | Recommendation |
|---|---|---|
| D1 | Legacy passwords | **Decided 2026-11-10:** random password for generation 1, credentials sent from the admin panel or forgot password; generation 2 keeps its hashes (see `EXPANSION_PLAN.md` X1). |
| D2 | Student support tickets: extend v3 tickets to students, or move them into chat? | Extend tickets; chat has no status or priority, and staff already work tickets. |
| D3 | v2 reviews: may approved ones appear on the public site? | Import all; show on course pages only with the student's consent, recorded. |
| D4 | Leaderboard: award points for historic attendance and results? | No. Start everyone at zero on cutover day; history stays visible. |
| D5 | Attendance: create "absent" for past sessions a student did not join? | No. Unknown is not absent. Only matters for batches running at cutover; mark those by hand. |
| D6 | Historic payments: receipts only, or retroactive invoices? | Receipts only. Ask the accountant before deciding otherwise. |
| D7 | What URL do the QR codes on v1 (pre-v2) certificates point to? | If it is a v1 URL, add that pattern to the redirect map too. |
| D8 | Same domain for v3? | Yes, so certificate links and search rankings carry over. |
| D9 | Certificate templates: build the full visual designer (L6) or a fixed design per course type? | Fixed design with an uploaded background first; designer later. |

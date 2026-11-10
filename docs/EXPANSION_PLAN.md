# Expansion plan: what v3 needs beyond the migration

Status: **plan only**. Nothing here is built. Companion to `docs/MIGRATION_PLAN.md`
(how live ubs-v2 data comes across) and `docs/PROJECT_PLAN.md` (Phases 0 to 9).
Every rule in `CLAUDE.md` applies unless this document says it changes, and where it
changes a rule it says so in **bold**.

Requirements came from the owner on 2026-11-10. Each section starts with what v3
already does, so the work is only the gap.

---

## 1. Accounts of the two older generations

**Requirement.** Data comes from two generations: v1 (folded into v2 in June 2026
through the `seed_legacy_*` migrations) and native v2. v1 students, whose records sit in
the public ubs-v2 repository, are converted fully to the v3 model and given a random
password for now. Admins can send fresh credentials from the panel, and students can
reset their own password.

**Already in v3.** `AccountCreator::resendCredentials()` generates a new temporary
password, forces a change at first sign in and writes a `credential_deliveries` row per
channel (`POST admin/{role}s/{user}/resend-credentials`). Forgot password exists and
answers identically for real and unknown addresses. There is also a per-batch
credentials screen.

**Plan.**

- The importer tags every account with its generation (`legacy_generation = 1 | 2` in
  `legacy_id_map`).
  - **Generation 1** is identified on the private server by matching the production
    rows against the emails in the June seed file. The hash comparison runs there too:
    if the stored hash equals the published one, the account is exposed.
  - That list never goes into a repository.
- **Generation 1 accounts:** the password is replaced with a random one that nobody
  ever sees. It is hashed straight away, never stored or shown, and
  `must_change_password = true` is set.
  - The account works the moment somebody sends credentials or the student uses
    forgot password.
  - Nothing is mailed during the import.
- **Generation 2 accounts:** these hashes were never published, so they are kept and
  students sign in as before. Whether to force a change anyway is decision X1.
- "Fully convert" means every v1 field reaches its v3 home, not the lossy June mapping:
  - profile fields (qualification, city, state, postal code)
  - college, where the course of study names one
  - enrolment status
  - attendance as `present` rows
  - certificates with their numbers kept
  - payments in paise
  
  Anything that cannot be placed is listed in the import report rather than dropped.
- **Credential sending, the gap.** *Built 2026-11-10 (Step B, first slice): bulk send from the Students and Clients lists, by selection or for everyone matching the current filters, and a "Credentials sent" column and filter (never, queued, sent, failed). One rule changed while building it: an account is skipped whenever its password is not a temporary one, signed in or not, because generation 2 accounts keep their own passwords and v2 never recorded sign ins.*
  - A **bulk send** on the Students list: select rows, or filter to "legacy, never
    sent", then Send credentials.
  - It is queued and throttled, so a mail provider limit does not fail half the list.
  - It shows progress, and it skips accounts that already signed in since the last send.
  - Each send goes through `AccountCreator`, so every one is a delivery row and an
    audit entry.
  - A per-student "Last credentials sent" column, filterable in the shared table.
  - The mail itself says why the student is getting it, so a migration does not look
    like phishing.

---

## 2. Google sign in: first-time registration

**Reported in v2.** A new user clicks "Continue with Google", lands back on the login
screen with nothing shown, and only the second click registers them.

**Why it behaves that way (read from the code):**

- Both `catch (\Exception $e)` blocks in `app/Http/Controllers/Auth/SocialiteController.php`
  redirect to `/login` with an error under the key `google`. They do not log the
  exception, so the server log has nothing either.
- `resources/js/Pages/Auth/Login.vue` never renders `errors.google`, so the failure is
  silent.
- `User::create()` runs outside any transaction, before the steps that can still fail:
  - login
  - session regenerate
  - writing `needs_role_selection`
  - the `auth_session` checks on `/auth/select-role`
- So the first attempt can leave a half-finished account behind. The second click finds
  that account by email, takes the existing-user path and goes straight to
  `/dashboard`. That is exactly the "works the second time" pattern.
- The exact failing line cannot be read from the code alone, because nothing is logged.
  It is not worth fixing v2, since v2 is being replaced.

**In v3.**

- `GoogleOAuthController` creates the account, profile and Google link in one
  `DB::transaction`, logs failures and shows them under `identifier`, which the login
  page renders.
- It signs the new student in through `LoginPipeline::complete()` in the same callback.
- `tests/Feature/Auth/GoogleLoginTest.php` asserts that the first callback for a new
  address lands on `/student`, signed in (7 tests passing as of 2026-11-10).
- v3 deliberately has no "choose your role" step, because clients are never
  self-registered through Google (see `CLAUDE.md`).

**Still to do.** Repeat the first-time flow in a real browser against real Google
credentials on staging, with a Google account that has never used the site.
`CLAUDE.md` asks for exactly that check before any sign-in change is called done.

---

## 3. Client management, completed

**Requirement.** A client who buys a product or takes a service sees everything in
their dashboard: progress, agreement, quotation, documents and keys. Documents are
digitally signed, printable and downloadable as PDF.

**Already in v3:**

- projects with milestones, updates and progress
- proposals, versioned and immutable once sent
- quotations with items
- document folders with versions
- maintenance contracts with an SLA copied onto tickets
- tickets, subscriptions and API keys (shown once, hashed)
- payment requests, frozen invoices with cached PDFs, transactions and refunds
- the client direct chat
- everything is scoped `forClient($user)`, so another client's record is not found
  rather than refused

**How the client panel works, end to end.** The stages a client goes through, and
where each one lives:

| Stage | What happens | Where it lives |
|---|---|---|
| 1. Discover | The visitor tries a live demo, asks for a custom build, buys from the cart, or talks to the assistant | Public site, §4, §9.2 → a lead |
| 2. Agree | The proposal and quotation are sent; the client reviews, comments and signs. The agreement (MSA, SOW, NDA) is signed by both sides. The advance becomes a payment request | Proposals, CL1, CL2 |
| 3. Onboard | The account is created through `AccountCreator`. A kick-off checklist ("what we need from you") and a kick-off meeting with minutes | CL6, CL10, CL11 |
| 4. Build | Milestones, weekly updates, a staging preview, feedback, change requests, documents, chat and meetings | Projects, CL12–CL13 |
| 5. Accept and pay | The client accepts a milestone, which raises its invoice. They pay by Razorpay, UPI or NEFT, with TDS handled | CL9, CL14 |
| 6. Hand over | Source code, deployment notes, licence keys, credentials and training material. The warranty starts | CL4, CL7, CL15 |
| 7. Look after | Maintenance contract, tickets on SLA, renewals, monthly reports, upgrade proposals | Contracts, CL16–CL17 |

A client who only **buys a product** goes from stage 1 to stage 6 in a single checkout:
order, licence, downloads, updates and support window.

**The dashboard leads with what needs the client.** "Waiting on you" comes first:
- sign this proposal
- pay this invoice
- accept this milestone
- answer these kick-off questions
- a renewal due in 14 days

After that come:
- project progress
- upcoming payments
- open tickets
- recent documents and updates

A client who opens the panel once a week should see in five seconds whether anything is
blocked on them.

**Gaps in the brief.**

| # | Gap | Notes |
|---|---|---|
| CL1 | **Agreements** as their own record: master service agreement, statement of work, NDA, licence agreement, each versioned like proposals and linked to a project, contract, subscription or order | Today an agreement can only be an uploaded document. |
| CL2 | **Digital signing** of agreements, proposals and quotations | See §3.1. |
| CL3 | **PDF and print for every client document type**: proposal, quotation, agreement, project status report, receipt (invoices already have one) | One renderer with print CSS. The PDF is generated when the document is sent and cached, so it freezes like invoices do. |
| CL4 | **Licence keys** for products bought with source code (§4): key, activations, domain binding where the product supports it, download entitlement | Separate from API keys, but shown and rotated the same way. |
| CL5 | **Order history** in the client dashboard: cart orders, what each contained, licence keys, downloads, invoices | Comes with §4. |
| CL6 | **Client onboarding from a purchase**: buying from the cart creates the client account through `AccountCreator`, the same as an admin would | Today only an admin creates clients; the cart becomes a second route in, through the same service. |
| CL7 | **A credentials vault for project handover** (hosting, domain, repository access) | Encrypted at rest, revealed per field with re-authentication, every reveal audited. Optional; decision X6. |

**Beyond the brief.** The owner's brief covers what a client should *see*. A
mature client portal also covers what the client and the team *do together*, and that
is where most project disputes start. Each item below is recommended. The ones in
**bold** have the most effect for the work involved.

| # | Improvement | Why it matters |
|---|---|---|
| CL8 | **Client organisations with several people.** A company account has members with roles: owner, authorised signatory, finance, technical. Invoices go to finance, tickets come from technical people, and only a signatory can sign. | Today a client is one user. Real clients are companies, and "who is allowed to sign" has to be answerable before e-signatures exist, so **this comes before CL2**. |
| CL9 | **Milestone acceptance.** At a milestone the client opens a sign-off with the deliverables listed. They accept it, or reject it with comments. Accepting raises that milestone's invoice (`project_milestones.payment_amount` already exists). | It turns "is it done?" into a recorded answer, and billing follows from it instead of from a reminder. |
| CL10 | **"Waiting on you" items** with due dates: brand assets, content, server access, decisions. They are shown on the dashboard, and the project timeline marks time spent blocked on the client. | The most common cause of delay is waiting on the client. Recording it keeps the timeline honest in both directions. |
| CL11 | **Meetings and minutes.** Schedule a call, then record the minutes as a document the client acknowledges. Action items become "waiting on you" items or tasks for the team. | Settles "we never agreed to that". |
| CL12 | **Change requests.** The client asks for something outside the scope. We estimate it and send it as a quotation addendum. The client approves (signs), and the scope, timeline and value update with the history kept. | It stops scope creep becoming free work or an argument, and it is a natural extension of the immutable proposal rule. |
| CL13 | Feedback on the staging preview: the client reports a bug or a change against the build, with a screenshot, and tracks its status. | Feedback sent over WhatsApp gets lost. Here it is linked to the milestone it blocks. |
| CL14 | **Indian B2B billing.** The client's GSTIN and PO number appear on invoices. **TDS:** the client pays the net amount, uploads their Form 16A, and the invoice settles. Also credit notes and a downloadable statement of account. | Most companies deduct TDS on services. Without handling it, every paid invoice shows a short payment and finance reconciles by hand. |
| CL15 | **Handover pack.** One page for each delivered project: repository access, a deployment guide, environment notes, licences, training videos, the warranty end date, and acknowledgement by the client. | It makes "done" final and is what a client comes back to a year later. |
| CL16 | Renewals and expiry: maintenance contracts, hosting, domain, SSL certificates and update windows, with reminders at 30, 14 and 3 days, plus auto-renew where it is a subscription. | Lapsed domains and SSL certificates are emergencies nobody needed. |
| CL17 | Monthly service report for maintenance clients: tickets against the SLA, uptime for hosted products, work done and hours used against the contract. | It shows the value of a contract at the moment the client decides whether to renew. |
| CL18 | Satisfaction: a one-question rating when a ticket closes, and a short survey at handover. With the client's written consent, a quote can become a public testimonial. | It feeds the testimonials section honestly, under the no-fabricated-proof rule. |
| CL19 | WhatsApp notifications through the WhatsApp Business API for key events (proposal sent, invoice due, milestone ready, ticket answered), alongside email, per person with opt-out. | Indian clients read WhatsApp first. |
| CL20 | Client-side security: 2FA required for signatories and finance, a list of their own sessions and devices, and an activity log of who in their organisation did what. | A signature or a payment is only as trustworthy as the account that made it. |

Nothing here changes the existing rules:
- every client read still starts from `forClient()`
- money is still paise
- a sent document is still immutable
- every change still goes through `Auditor`

CL8 widens `forClient()` from one user to the organisation that user belongs to, in that
one place, so the rule still has one answer.

### 3.1 Digital signatures

Two levels. The choice is decision X2.

1. **Built-in e-signature (OTP).**
   - Steps: the signer reviews the PDF, types their name, confirms an OTP sent to their
     registered email or mobile (the existing `otp_codes` machinery), then signs.
   - The platform stores:
     - the SHA-256 of the exact PDF
     - signer, time, IP and user agent
     - the OTP verification
   - It appends a certificate-of-signing page. Our countersignature is the same flow.
   - A signed document is immutable. Changes mean a new version, as proposals already
     work.
   - Suitable for quotations, proposals and most service agreements, as evidence of
     acceptance.
2. **Aadhaar eSign or DSC through a licensed eSign provider**, for agreements that need
   an electronic signature recognised under the IT Act, 2000.
   - The provider does the signing.
   - The platform sends the PDF, stores the signed one and records the provider's
     reference.
   - The provider is chosen by the owner, with keys in `.env` per the credentials rule.

Whichever applies, the client sees the status (sent, viewed, signed, countersigned)
and can download the signed PDF. "Viewed" is recorded only when the client actually
opens it.

---

## 4. Products, live demos, cart and coupons

**Requirement:**

- some products already exist and get a live demo on our own site
- others are built to order in the client's preferred technology
- some software is sold with source code at a fixed price, with discount coupons
- a cart like Hostinger's

**Already in v3:**

- `solutions` (the catalogue) with bands, tech stack, modules and timelines
- `services`
- leads
- Razorpay checkout that prices server side (`Checkout::begin()`)
- payment requests and invoices

There is no cart, no coupons, no fixed-price product and no demo. Coupons are named as
not built in `CLAUDE.md`.

**Product types.** A solution gains `offering` values; one product may have several:

| Offering | What the visitor can do | Price shown |
|---|---|---|
| `demo` | Open a live demo with demo credentials, reset on a schedule | n/a |
| `custom_build` | Ask for it built in their stack → lead → proposal → quotation → project (existing flow) | **Band**, as today |
| `source_code` | Add to cart at a fixed price, choose a licence tier and add-ons, pay, download | **Fixed price** |
| `subscription` | Hosted product billed monthly or yearly (existing subscriptions) | Fixed plan price |

**Rule change: prices on the catalogue.** `CLAUDE.md` says prices are bands, never
figures.
- That stays true for `custom_build`.
- A fixed figure is allowed **only** for an offering that is actually sold at that
  figure: `source_code` and `subscription`. The figure is the price charged, not an
  estimate.
- `CLAUDE.md` gets updated when this lands.

**Live demos.**
- Each demo is a separate deployment on its own subdomain, never inside this
  application or its database.
- It uses demo credentials shown on the product page and a scheduled reset that
  restores seed data, and it sends no mail.
- The product page records demo opens as a lead signal only when the visitor has
  agreed to it.

**Cart and checkout.**

- **Cart:** a guest can fill a cart in the session; it is persisted to the account once
  they sign in or register at checkout.
- **Line items:** a product with a licence tier (single site, multiple sites, extended),
  plus add-ons (installation, customisation hours, N months of support), each a priced
  option on the product.
- **Coupon codes:**
  - percentage or fixed amount, in paise
  - minimum order and an expiry window
  - limits on total uses and on uses per account
  - restricted to some products or categories, or to the first order
  - stackable or not (default not)
  - Validated and applied **on the server at checkout**, never trusted from the page.
  - Every redemption is recorded against the order.
- **Totals:** GST worked out per line with `Money`, shown as a breakdown before payment.
  Place of supply comes from the billing address, so CGST/SGST or IGST is right.
- **Payment:** through the existing `Checkout`.
  - On success the order becomes paid and issues the frozen invoice.
  - It creates the licence key and download entitlement.
  - It creates the client account if the buyer was new (§3 CL6).
  - It sends the receipt.
- **Downloads:** signed, expiring URLs, counted per entitlement, with every product
  version kept. A licence holder can download updates during their update window.
- **Refunds:** go through the existing `refunds`, and revoke the licence and entitlement.
- **Admin:** products and offerings, licence tiers, add-ons, coupons, orders, licence
  management, and a sales report in `ReportLibrary`.

---

## 5. Course registration forms without signing in

**Requirement.** For some courses, a registration form opens that anybody can fill
without signing in.
- If the email is already registered, the course appears in that student's panel.
- If the email is new, an account is created and the credentials are mailed.

**Plan.**

- **Admin builds a form per course or batch:**
  - fields: the standard ones plus custom questions
  - an opening and closing date and a capacity
  - free or paid
  - auto-approve or review
  - a public URL and a QR code for posters or college visits
- **On submit, the screen always shows the same message:** "Thanks — check your email."
  - That holds whether or not the email exists, which keeps the never-confirm-an-account
    rule.
  - Turnstile or a CAPTCHA plus rate limiting, because this form creates accounts.
- **New email:**
  - an account is created through `AccountCreator`, which sends the credentials
  - the registration is attached to it
- **Existing email:**
  - the registration is attached to that account as **pending**
  - an email asks the account holder to confirm it (or it appears in their panel to
    confirm)
  - Auto-enrolling anyone whose email somebody else typed would let strangers enrol
    people into courses (decision X5).
- **Paid courses:** registering creates a payment request. Access opens only once it is
  paid, through the same content gate as today.
- **Admin side:** submissions go into the shared table with approve, reject and
  move-to-batch actions, and export to CSV/Excel.

---

## 6. LMS: every v2 module, plus improvements

**v2 parity** is the L1–L11 list in `docs/MIGRATION_PLAN.md` §6:

- enrolment management with offline payments
- the full student profile (block, reset, audited impersonation)
- per-batch quizzes and materials
- extra quiz attempts
- assignment resubmission with history
- certificate templates
- a list of live classes across batches
- notices to one student
- review moderation
- public enrolment with checkout
- an "enrolment open" toggle

These are required before v2 is switched off.

**Improvements for the learning experience:**

- **Next step on the dashboard:** the student's next lesson, class, due assignment and
  open quiz, in one card. The "continue where you left off" link uses
  `lesson_completions.seconds_spent`.
- **Reminders:**
  - before a class (1 day and 1 hour)
  - before an assignment or quiz closes
  - after missing two classes, which feeds the existing warnings ladder
  
  Each reminder can be turned off by the student.
- **Recordings** attached to the session they belong to, so a missed class is one click
  away.
- **Doubts:** a question thread per lesson, answered by the trainer, with answers visible
  to the batch.
- **Notes and bookmarks:** per lesson, private to the student.
- **Progress from completions:** visible per module, never typed in.

**Performance and leaderboard.**

- `leaderboard_points` and `Activity::did()` already exist, and points are awarded once
  per reason.
- **Gap:** a **performance page** per student. The student and staff both see it.
  - attendance %
  - quiz trend
  - on-time submission rate
  - lessons completed
  - rank in batch
- **Gap:** a **batch performance view** for trainers that shows who is slipping before
  a warning is needed.
- **Leaderboards:** per batch, per course and all-time, plus a weekly board so a late
  joiner can still top something. Students can hide their name from the public board.

**Online activity tracking.**

- Things to track:
  - signed in
  - opened a lesson, and time on it in coarse buckets
  - joined a class
  - submitted
  - attempted a quiz
  - posted in the group
  
  Every one goes through `Activity::did()` into `activity_logs`.
- "Online now" in a batch comes from the Reverb presence channel the chat already uses.
- An activity heatmap per student and per batch.
- Students are told what is tracked, on their settings page. It is not used to grade
  anyone without a person looking at it.

---

## 7. Batch group chat

**Requirement:**
- a group per batch over WebSockets
- the main admin is the default group admin in every group
- only images as media, plus voice notes

**No AI moderation in chat** (owner, 2026-11-10): not for text, images or voice notes.
Moderation in groups is done by people, with the admin controls below. AI is used on
quizzes instead (§9.1).

**Already in v3:**

- `Rooms::forBatch()` creates a `batch_group` per batch and keeps its members in step
  with the batch roster. The trainer joins automatically.
- Text, image and audio only, enforced by an enum. Types are read from bytes, and
  images are re-encoded, which strips EXIF.
- Live over Reverb, falling back to polling.
- Typing indicators and read receipts.
- Removed messages keep their bubble.

So the allowed media types are already exactly what was asked for.

**Gaps.**

- **Owner as group admin in every batch group.**
  - The owner is joined with role `admin` when the group is created.
  - Existing groups are backfilled.
  - Notifications for that membership are muted by default, which keeps the reason
    `CLAUDE.md` gives for "reading is not joining": no unread badge for every batch on
    the platform.
  - **This changes the "join by replying" rule, for the owner only.** Other staff keep
    it.
- **Group admin controls.**
  - remove a message (audited)
  - mute a student for a period
  - pin a message
  - announcement mode, where only admins and the trainer can post
  - report a message (any member), which goes to a queue for the group admins

---

## 8. Email: festival greetings and broadcasts

**Requirement:**
- on every important day in the Indian calendar, every registered email gets a
  greeting, enquiry senders from the contact form included
- the admin can broadcast to everyone, or to a target group

**Plan.**

- **Calendar:**
  - an admin-managed `occasions` table: name, date for each year, audience, template and
    an on/off switch
  - lunar festivals move every year, so next year's dates are confirmed by a person each
    year, with a reminder 30 days before the first one
  - seeded with national days (Republic Day, Independence Day, Gandhi Jayanti) and the
    major festivals, each switchable
- **Templates:** each occasion has its own template (subject, message, image) with a
  preview and a test send to yourself.
- **Consent and unsubscribe, required:**
  - every greeting and broadcast carries a one-click unsubscribe, honoured instantly, and
    a footer saying why the person received it
  - enquiry senders are included only while they have not opted out
  - service mail (credentials, receipts, class reminders) is separate from promotional
    mail and is unaffected by unsubscribing from the latter
- **Broadcasts:**
  - compose with templates and variables
  - pick the audience: all users, role, course, batch, college, enrolment status,
    enquiry source, signed-up date range, never-signed-in
  - a live recipient count before sending
  - test send, schedule, then send
- **Delivery:**
  - queued in throttled batches through a transactional mail provider with SPF, DKIM
    and DMARC set up for the domain
  - bounces and complaints come back by webhook and suppress that address
  - per-send statistics: delivered, bounced, unsubscribed, and opened where the provider
    reports it
- **Control:**
  - a permission per action (`mail.broadcast`, `mail.occasions`)
  - every send goes through `Auditor`
  - a cap on how many promotional mails one address gets per week

---

## 9. AI features

Both features use the Claude API through the official PHP SDK
(`composer require anthropic-ai/sdk`). The key lives in `.env` and the defaults in
`config/company.php`, per the credentials rule.

- **Model:** `claude-opus-5-5` by default. A cheaper model per route is the owner's call
  (decision X3).
- **Chat moderation is not part of this plan** (owner, 2026-11-10); see §7.

### 9.1 Quiz integrity: catching cheating

**Requirement.** AI moderation on quizzes, so that cheating does not pass unnoticed.

**Already in v3.**
- The clock is the server's: `expires_at` is set when the attempt starts, and a late
  submission is marked on what was already stored.
- The answer key never reaches an open attempt.
- Questions are shuffled with a seed per attempt.
- Written answers wait for a person (`needs_review`), and are never scored zero in the
  meantime.

**Principle.** The AI **flags; a person decides.**
- It never fails an attempt, changes a score or issues a warning by itself.
- A student is never shown "suspected of cheating". They see their result once a person
  has looked, which is the same reasoning `CLAUDE.md` gives for written answers.
- Every signal below can be innocent: a notification steals focus, a family shares a
  router, two good students write the same correct answer. The AI's job is to put the
  evidence in front of a trainer, ranked, with reasons.

**Layer 1: make cheating harder (no AI).** Most of the value is here.
- **Question pools:** the quiz draws N questions from a larger pool, so neighbours get
  different papers.
- **Option shuffling** per attempt, alongside the existing question shuffling.
- **One question at a time**, optionally with no going back, set per quiz.
- **Answers and the review open only after the quiz closes for everyone**, so the first
  student to finish cannot pass the answers on while others are still sitting it.
- **One open attempt per student.** The attempt is tied to the browser session that
  started it; a second device sees "attempt in progress elsewhere", and the switch is
  logged.
- **The rules are shown before starting:** what is recorded during the attempt and what
  happens to it. A recorded rule nobody was told about is a trap, not a deterrent.

**Layer 2: record what happened during the attempt (no AI).**
- A new `quiz_attempt_events` table records, with server timestamps:
  - start, resume and submit
  - each answer saved, with time spent on that question
  - the tab hidden or the window losing focus, and for how long
  - leaving and returning to full screen, where the quiz asks for it
  - paste into an answer box, and copy from the question text
  - a new IP address or device mid-attempt
- These come from the browser, so they can be faked or missed. They are evidence, not
  proof, and the review screen says so.
- Deterministic checks run when the quiz closes, before any AI:
  - **Speed:** many questions answered implausibly fast, or a hard question answered in
    seconds after a long focus loss.
  - **Overlap:** two students in the same batch with near-identical written answers
    (normalised-text similarity), or the same unusual wrong choices in the same order.
  - **Network:** several students submitting from one IP within minutes with matching
    answers.

**Layer 3: AI review of flagged attempts.**
- **When:** after the quiz closes for the whole batch, so answers can be compared across
  students. Nothing during the attempt depends on the API being up.
- **What it gets:** a summary of the events, the deterministic flags, the student's
  written answers, and the closest matching answers from other students in the batch.
  Student identities are replaced by labels in what is sent.
- **What it returns:** structured output with a risk level (none, low, medium or high),
  a list of evidence items each pointing at a specific event or answer, and the
  innocent explanations it considered.
- **Written answers:** it compares meaning, not just wording. It can see that two answers
  are the same paraphrased, which plain text similarity misses.
  - It **does not** label anything as "written by AI". Detectors for that are unreliable
    and would accuse honest students. A pasted block of text and its timing are evidence
    a person can weigh; a guess about authorship is not.
- **Cost:** it runs through the Message Batches API at half price, since nothing waits on
  it. Only attempts the deterministic checks flagged, or that a trainer asks about, are
  sent; at most every attempt of a quiz.
  - Rough size: an attempt of 20 questions with its events is a few thousand tokens.
  - At Opus 5.5 rates that is a few US cents per attempt reviewed.

**Layer 4: the trainer's review.**
- A **Quiz integrity** tab on the quiz shows flagged attempts, highest risk first.
- Each attempt has:
  - its timeline
  - side-by-side answers with matching ones highlighted
  - the AI's evidence list
- The reviewer can:
  - clear it
  - discuss it with the student
  - void the attempt and allow a resit
  - or open a warning on the existing ladder, which the student is told about, as today
- Every decision goes through `Auditor`, with the reviewer and their reason.
- The student can see the final decision and the reason, and can reply once before it is
  final.

### 9.1a Proctored quizzes: lockdown and live camera

**Requirement** (owner, 2026-11-10):
- the quiz goes full screen as soon as it starts, and the student cannot leave full
  screen until they submit or time runs out
- laptops and computers only, never phones
- closing the browser, switching tabs and opening developer tools are blocked
- copy and paste raise an alert
- camera and microphone permission is required, and the video reaches the server
- the owner watches every test live
- **nothing is recorded:** the stream exists only while the test is running

A quiz gets a `proctoring` setting: **off**, **lockdown** (browser controls only) or
**live** (lockdown plus camera and microphone).

**What a web page can and cannot do.** Browsers do not let a page take control of the
computer. That is a security rule of every browser, not a gap in this plan. So each
requirement is either **enforced**, **detected and answered** (the quiz locks, the event
is logged, the proctor is alerted live), or **out of reach of a web page**:

| Requirement | In a normal browser (Chrome or Edge) | How |
|---|---|---|
| Full screen as soon as the quiz starts | **Enforced** | The "Start quiz" click itself requests full screen. A browser allows that only from a click, so the start button is that click. |
| Cannot leave full screen | **Mostly enforced, always detected** | Chrome and Edge's Keyboard Lock captures Esc, so leaving needs Esc held down for two seconds rather than a tap. If the student still leaves, the questions are covered at once by "Return to full screen to continue", the exit is logged, and the proctor's screen flags it live. A page cannot make leaving impossible. |
| Laptop or computer only | **Enforced at start, can be spoofed** | Device checks (client hints, touch and pointer type, screen size) on the page and again on the server when the attempt starts. A phone in "desktop mode" can pass, but the camera shows it. |
| No tab switching | **Detected and answered** | Leaving the tab or window covers the questions, logs the event with how long it lasted, and alerts the proctor. Alt+Tab, the Windows key and Cmd+Tab are handled by the operating system, so a page cannot block them, only notice them. |
| No closing the browser | **Discouraged, detected** | The browser shows its own "Leave site?" prompt. If the student closes anyway, the attempt keeps running on the server clock; reopening resumes it, and the gap is logged and flagged. |
| No copy or paste | **Enforced** | Copy, cut, paste, right click, text selection and drag are blocked inside the quiz. Each try shows the student a warning, is logged, and is counted on the proctor's screen. |
| No developer tools | **Shortcuts blocked; opening via the menu cannot be stopped** | F12 and Ctrl+Shift+I/J/C are blocked. The browser menu still opens developer tools, and detecting it reliably is not possible. **The real protection is that the answer key never reaches the browser** (`Question::forAttempt()`), so developer tools show the student nothing worth having. |
| Camera and microphone | **Enforced** | Permission is required before the first question appears. If either is refused or drops, the questions are covered until it is back, and the proctor sees "camera off" on that tile. |

**If the owner needs truly locked computers** for a high-stakes exam, the only real
option is a lockdown browser.
- [Safe Exam Browser](https://safeexambrowser.org) is free and open source, for
  Windows and macOS. It blocks app switching, other windows, shortcuts and developer
  tools at the operating-system level.
- A quiz can require it: the server checks the configuration key Safe Exam Browser sends
  with each request, and refuses the attempt in an ordinary browser.
- This is decision X10. Recommendation: plain browser lockdown for regular quizzes;
  Safe Exam Browser plus live camera for final assessments.

**Live camera, without recording.**

- **Media path:**
  - The student's browser sends camera and microphone over **WebRTC** to a self-hosted
    **media relay**, an SFU such as LiveKit.
  - The SFU forwards the streams to the proctor's screen and **stores nothing**.
  - A TURN server is needed for students behind strict networks.
- **Laravel's part:**
  - It issues short-lived join tokens for the student (send only) and the proctor
    (watch only).
  - It ties the stream to the attempt.
  - Reverb carries status: full screen, focus, copy attempts, camera state.
  - Laravel never handles video.
- **"Not recorded" in practice:**
  - recording and egress are switched off in the SFU configuration
  - nothing is written to disk
  - the stream ends when the attempt is submitted or expires
  - this is stated in the privacy notice
  - The platform cannot stop somebody filming their own screen, so the policy says
    proctors must not.
- **Bandwidth, important on Indian home connections:**
  - Each student sends a small stream (about 320×240 at 10–15 fps, roughly 150–300 kbps).
  - The proctor's grid uses thumbnails.
  - Clicking a tile opens that student large, with sound.
  - A weak connection lowers the quality before it drops the stream.

**The proctor console** (owner, plus staff given `quiz.proctor`):
- A live grid of every student in the attempt. Each tile carries badges: full screen,
  in focus, camera, microphone, and a count of exits and copy tries.
- Tiles with a fresh violation move to the top.
- Click to enlarge, with sound.
- **Message a student:** the message appears on top of their quiz ("Please keep your
  face in view"). That makes it plain somebody is watching, which is the deterrent the
  owner wants.
- **End an attempt with a reason.** This is audited, the student is told, and the trainer
  decides afterwards whether to allow a resit, as in Layer 4.
- **Grant extra minutes** after a genuine network drop, also audited.
- One person can watch about 20–30 students properly. Bigger batches need more proctors,
  or the quiz split into slots.

**What the student sees.**
- **Before starting:** a consent screen saying:
  - camera and microphone are streamed live to Unboundbyte staff during the test only
  - nothing is recorded
  - what counts as a violation and what happens after one
  
  They must agree to start. Students under 18 need a guardian's consent recorded first.
- **During the test:** a permanent "LIVE · proctored" badge with their own camera preview
  in a corner, so they know they are visible.
  - It must not say "recording", because it is not.
- **Each violation:** an on-screen warning that it was logged, with a running count.

**Rules that keep it fair.**
- **Proctored quizzes run in scheduled slots,** so a proctor is actually present. A live
  stream at 2 a.m. with nobody watching deters nobody and still costs the student their
  privacy.
- **The clock stays the server's.** A covered screen does not pause it; extra time is a
  proctor's decision.
- **A per-quiz rule, set by the admin and shown before the start,** can auto-submit after
  N exits from full screen or the tab. Otherwise violations are evidence for Layer 4, not
  automatic fails.
- **No AI watches the video.** AI stays on the event log and the answers (Layer 3), as
  the owner ruled out AI on images.
- **Students without a webcam** need an alternative arranged in advance: a loaner, a
  centre, or a supervised offline sitting.

### 9.2 Visitor assistant on the public site

**What it does.** It asks what the visitor is trying to do and points them to the right
place:
- a solution with a demo
- a custom build enquiry
- a course or internship
- the current batch
- an open registration form
- the contact form

**How.**

- **A chat widget** on public pages. Replies stream, and a tool-use loop gives the model
  only these read tools:
  - search the catalogue (solutions, services, courses, internships)
  - get one item
  - list open batches and registration forms
  - get page links
- **Capturing details:** with the visitor's explicit agreement, a `create_lead` tool
  records their name, contact and need as a lead, with the conversation summary.
- **Grounding:**
  - It answers only from the catalogue tools.
  - It never invents a price, a seat count, a date or a guarantee.
  - Prices follow the same rule as the pages: bands for builds, fixed figures only for
    fixed-price offerings.
  - "I don't know, here is the contact form" is an acceptable answer.
- **Safety:**
  - Visitor text is untrusted, and the tools are read only apart from `create_lead`.
  - Requests are rate limited per IP and conversation.
  - A conversation is capped in length, then hands over to the contact form or chat
    with staff.
  - No visitor data is sent until the visitor agrees.
- **Privacy:**
  - A notice on the widget says it is an AI assistant and what happens to what they
    type.
  - Transcripts are kept for a fixed period, then deleted.
- **Admin:**
  - conversations, the leads they produced, and common questions it could not answer
    (which show where the site copy is missing something)
  - an on/off switch

---

## 10. Order of work

Cutover from v2 needs only what v2 can do today (LMS parity, CMS, the importer). v2 is
the exposed system, so **cutover comes first and the new business features ship after
it on v3**, rather than all of it holding v2 alive.

| Step | Contents | Unblocks |
|---|---|---|
| **A** | Migration M0 (contain the exposure) | — |
| **B** | §1 credential bulk send · §6 v2 parity (L1–L11) · CMS (MIGRATION_PLAN §6, C1–C10) | Cutover |
| **C** | Migration M3–M5: importer, rehearsals, cutover | v2 switched off |
| **D** | §5 registration forms · §6 improvements, performance, activity · §7 chat admin controls | — |
| **E** | §3 client management: CL8 organisations first, then CL1–CL3 agreements, e-sign, PDFs, then CL9–CL20 in the order of the table | §4 |
| **F** | §4 products, demos, cart, coupons, licences, downloads | — |
| **G** | §8 email occasions and broadcasts | — |
| **H** | §9.1 quiz integrity · §9.1a proctored quizzes · §9.2 visitor assistant | — |
| **I** | Phases 8 and 9 of `PROJECT_PLAN.md` (mobile API, hardening) | — |

**Other rules for this order:**
- D–H are independent once C has landed and can run in any order.
- Each step ships on its own.
- None is called done until it has been checked in a real browser, as `CLAUDE.md` asks.

---

## 11. Decisions needed

**The owner accepted every recommendation below as the default on 2026-11-10.** Any
new question that comes up within a phase is asked before that phase starts, not in
the middle of it.

| # | Question | Recommendation |
|---|---|---|
| X1 | Generation 2 accounts: keep their passwords, or force a change too? | Keep. Their hashes were never published. |
| X2 | Digital signatures: built-in OTP e-sign, Aadhaar eSign through a provider, or both? | Both: OTP for quotations and proposals, a provider for agreements. Check with a lawyer which agreements need which. |
| X3 | AI model per feature (Opus 5.5 default; a cheaper model is your choice) | Your call on cost. Quiz review is low volume and batched, so the default costs little there. |
| X4 | Live camera proctoring | **Decided 2026-11-10:** yes, live only, never recorded (§9.1a). Still open: students under 18 (guardian consent) and students without a webcam. |
| X10 | Safe Exam Browser for final assessments, on top of browser lockdown? | Yes for final assessments; plain browser lockdown for regular quizzes. |
| X11 | Auto-submit after N full-screen or tab exits, or leave it to the reviewer? | Leave it to the reviewer by default; allow the rule per quiz, shown to students before they start. |
| X12 | Media relay: self-host LiveKit (with a TURN server) or a managed service? | Self-host on a separate server; recording off at the configuration level. |
| X5 | Registration form with an existing email: confirm first, or add directly? | Confirm first. Otherwise anyone can enrol anyone. |
| X6 | Credentials vault for client handover: build it? | Only if the team hands over credentials through the platform today; it is a security surface. |
| X7 | Festival list: which occasions, and do enquiry senders receive greetings? | National days to everyone who has not opted out; festivals switchable by the admin; enquiry senders included, with unsubscribe. |
| X8 | Transactional mail provider for bulk sending | Any provider with bounce webhooks and an Indian billing option; credentials in `.env`. |
| X9 | Demo hosting for live demos: where, and how often reset? | A separate small server, reset nightly. |

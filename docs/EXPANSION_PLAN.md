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
- **Credential sending, the gap.**
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

**Gaps.**

| # | Gap | Notes |
|---|---|---|
| CL1 | **Agreements** as their own record: master service agreement, statement of work, NDA, licence agreement, each versioned like proposals and linked to a project, contract, subscription or order | Today an agreement can only be an uploaded document. |
| CL2 | **Digital signing** of agreements, proposals and quotations | See §3.1. |
| CL3 | **PDF and print for every client document type**: proposal, quotation, agreement, project status report, receipt (invoices already have one) | One renderer with print CSS. The PDF is generated when the document is sent and cached, so it freezes like invoices do. |
| CL4 | **Licence keys** for products bought with source code (§4): key, activations, domain binding where the product supports it, download entitlement | Separate from API keys, but shown and rotated the same way. |
| CL5 | **Order history** in the client dashboard: cart orders, what each contained, licence keys, downloads, invoices | Comes with §4. |
| CL6 | **Client onboarding from a purchase**: buying from the cart creates the client account through `AccountCreator`, the same as an admin would | Today only an admin creates clients; the cart becomes a second route in, through the same service. |
| CL7 | **A credentials vault for project handover** (hosting, domain, repository access) | Encrypted at rest, revealed per field with re-authentication, every reveal audited. Optional; decision X6. |

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
- AI moderation

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
  - report a message (any member)
- **AI moderation:** see §9.1.

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
- **Volume note for that call:** moderation runs on every chat message. Opus 5.5 is
  $4 / $20 per million input/output tokens; Haiku 5.5 is $0.10 / $0.50.

### 9.1 Chat moderation

- **Text:** the message is stored and broadcast as now (store first, broadcast second).
  - A queued job then classifies it.
  - It uses structured output (`verdict`: allow, hide or review; `categories`; a short
    `reason`) and low effort.
  - The system prompt is cached, so each message costs only its own tokens.
  - A `hide` verdict replaces the bubble with "removed by moderation" (the existing
    removed-message behaviour) and notifies the group admins.
  - A `review` verdict leaves it visible and puts it in a moderation queue.
- **Images:** held as "sending…" until the classifier answers, then broadcast. An image
  that 40 students have already seen cannot be taken back.
  - The image is re-encoded first, as today, so the model sees the EXIF-stripped copy.
- **Voice notes:** the Claude API does not take audio, so there are three options
  (decision X4):
  1. speech-to-text through a separate provider, then moderate the transcript
  2. no automatic moderation for voice: report button plus the admin queue
  3. voice notes limited to trainers and admins
- **Fail open, safely:** if the API is down, text and voice are delivered and queued for
  checking later; images wait up to a timeout, then go to the review queue.
- **People decide in the end:**
  - every automatic hide can be restored by an admin, and the student is told why
  - a student who gets repeated hides moves up the warnings ladder only by a person's
    decision
  - the model never issues warnings on its own
- Moderation decisions are logged with the model, the verdict and the reason.

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
| **E** | §3 client management (agreements, e-sign, PDFs, licences) | §4 |
| **F** | §4 products, demos, cart, coupons, licences, downloads | — |
| **G** | §8 email occasions and broadcasts | — |
| **H** | §9.1 chat moderation · §9.2 visitor assistant | — |
| **I** | Phases 8 and 9 of `PROJECT_PLAN.md` (mobile API, hardening) | — |

**Other rules for this order:**
- D–H are independent once C has landed and can run in any order.
- Each step ships on its own.
- None is called done until it has been checked in a real browser, as `CLAUDE.md` asks.

---

## 11. Decisions needed

| # | Question | Recommendation |
|---|---|---|
| X1 | Generation 2 accounts: keep their passwords, or force a change too? | Keep. Their hashes were never published. |
| X2 | Digital signatures: built-in OTP e-sign, Aadhaar eSign through a provider, or both? | Both: OTP for quotations and proposals, a provider for agreements. Check with a lawyer which agreements need which. |
| X3 | AI model per feature (Opus 5.5 default; a cheaper model for high-volume moderation is your choice) | Your call on cost; measure on real messages first. |
| X4 | Voice-note moderation: speech-to-text provider, report-only, or staff-only voice? | Report-only to start, add speech-to-text if abuse shows up. |
| X5 | Registration form with an existing email: confirm first, or add directly? | Confirm first. Otherwise anyone can enrol anyone. |
| X6 | Credentials vault for client handover: build it? | Only if the team hands over credentials through the platform today; it is a security surface. |
| X7 | Festival list: which occasions, and do enquiry senders receive greetings? | National days to everyone who has not opted out; festivals switchable by the admin; enquiry senders included, with unsubscribe. |
| X8 | Transactional mail provider for bulk sending | Any provider with bounce webhooks and an Indian billing option; credentials in `.env`. |
| X9 | Demo hosting for live demos: where, and how often reset? | A separate small server, reset nightly. |

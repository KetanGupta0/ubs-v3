# Data model overview

Indicative schema for the phases in `PROJECT_PLAN.md`. Column lists are abbreviated to the
fields that carry meaning. Every table gets `id`, timestamps, and soft deletes where
history matters.

## Identity (Phase 1)

| Table | Key columns |
| --- | --- |
| `users` | name, email, mobile, password, role (`admin`/`client`/`student`), status, avatar, email_verified_at, mobile_verified_at, must_change_password, last_login_at |
| `user_profiles` | user_id, company, designation, address, city, state, pincode, gstin, dob, guardian_name, guardian_mobile, college_id, enrollment_number, course_of_study, current_semester, graduation_year |
| `permissions` / `role_permissions` | granular capability flags for sub admins |
| `otp_codes` | user_id, channel (`email`/`sms`), code_hash, purpose, expires_at, consumed_at, attempts |
| `two_factor_secrets` | user_id, secret, confirmed_at, recovery_codes |
| `social_accounts` | user_id, provider, provider_user_id, avatar |
| `personal_access_tokens` | Sanctum, extended with device_name, platform, push_token |
| `auth_audit_logs` | user_id, event, ip, user_agent, succeeded |

One `users` table with a role column, not three tables. A single login form needs a single
identity, and a person could in principle be both a client and a student.

## Public site and catalogue (Phase 2, managed in Phase 3)

| Table | Key columns |
| --- | --- |
| `solution_categories` | name, slug, icon, sort_order |
| `solutions` | title, slug, category_id, summary, description, industry, platforms, tech_stack (json), features (json), modules (json), price_band_min, price_band_max, timeline_weeks, hero_media, gallery (json), is_featured, is_published, seo (json) |
| `services` | title, slug, type (`development`/`upgrade`/`maintenance`), description, deliverables (json), engagement_models (json) |
| `leads` | reference, name, email, mobile, company, college_name, student_count, message, interest, source_page, solution_id, service_id, course_id, status, assigned_to, converted_user_id |
| `testimonials`, `faqs`, `pages` | content managed from admin |

## Client module (Phase 4, built)

Every money column below stores **paise as an integer**. A decimal column still
arrives in PHP as a float unless every read remembers not to, and a total
assembled from floats drifts onto an invoice somebody then has to defend.


| Table | Key columns |
| --- | --- |
| `projects` | client_id, code, name, solution_id, description, status, phase, progress_percent, start_date, target_date, budget, manager_id |
| `project_milestones` | project_id, title, description, due_date, completed_at, order, progress_percent |
| `project_updates` | project_id, author_id, body, attachments (json), visible_to_client |
| `proposals` | client_id, project_id, number, version, title, body, valid_until, status, responded_at |
| `quotations` | proposal_id, client_id, number, subtotal, tax, total, currency, status |
| `quotation_items` | quotation_id, description, quantity, unit_price, tax_rate, amount |
| `documents` | client_id, project_id, folder_id, name, path, mime, size, version, uploaded_by, visibility |
| `maintenance_contracts` | client_id, project_id, plan, scope (json), starts_at, ends_at, sla_hours, amount, status |
| `support_tickets` | contract_id, client_id, project_id, subject, priority, status, first_response_at, resolved_at |
| `ticket_messages` | ticket_id, author_id, body, attachments (json) |
| `subscriptions` | client_id, subscribable (project/product/api plan), starts_at, renews_at, amount, interval, status, auto_renew |
| `api_key_plans` | name, description, quota, rate_limit, price, interval |
| `api_keys` | client_id, plan_id, project_id, key_prefix, key_hash, status, quota_used, expires_at, last_used_at |
| `api_key_usage_logs` | api_key_id, date, request_count |

## Learning management (Phase 5)

| Table | Key columns |
| --- | --- |
| `courses` | title, slug, type (`course`/`programme`/`internship`), summary, description, level, duration_weeks, duration_months, hours_per_week, price, sale_price, visibility (`public`/`lms_only`), mode, project_focus, documents_provided (json), syllabus (json), is_published |
| `colleges` | name, slug, city, state, university, coordinator name/email/mobile, mou_signed_on, mou_expires_on, is_active |
| `internship_documents` | user_id, course_id, batch_id, kind (`offer_letter`/`certificate`/`project_report`/`mentor_evaluation`), number, issued_at, payload (json), verification_code, pdf_path, issued_by — one of each kind per student and course |
| `mentor_reviews` | user_id, batch_id, week_number, reviewer_id, reviewed_on, summary, what_went_well, to_improve, marks (json) — one per student and week |
| `course_modules` | course_id, title, summary, sort_order, unlock_after_days, is_published |
| `lessons` | course_id, course_module_id, title, slug, summary, content, video_url, duration_minutes, sort_order, unlock_after_days, unlock_at, prerequisite_lesson_id, requires_payment, required_quiz_id, min_quiz_score, is_preview, is_published |
| `lesson_completions` | lesson_id, user_id, completed_at, seconds_spent — unique per lesson and student |
| `materials` | course_id, lesson_id, title, description, path, external_url, mime, size, is_downloadable, uploaded_by |
| `batches` | course_id, name, code, trainer_id, college_id, starts_on, ends_on, capacity, seats_taken, schedule (json), meet_link, status |
| `enrollments` | user_id, course_id, batch_id, status, source (`self`/`admin`/`college`), enrolled_at, completed_at, progress_percent, has_paid, payment_request_id — unique per student, course and batch |
| `live_sessions` | batch_id, lesson_id, title, agenda, scheduled_at, duration_minutes, meet_link, recording_url, calendar_event_id, status, trainer_id |
| `attendances` | live_session_id, user_id, status (`present`/`late`/`absent`/`excused`), marked_by, marked_at, note — unique per session and student |
| `quizzes` | course_id, course_module_id, title, instructions, time_limit_minutes, attempts_allowed, pass_percent, shuffle_questions, show_answers, opens_at, closes_at, is_published |
| `questions` | quiz_id, type (`mcq`/`multi`/`truefalse`/`short`), body, options (json), correct (json), explanation, marks, sort_order |
| `quiz_attempts` | quiz_id, user_id, batch_id, attempt_number, started_at, **expires_at**, submitted_at, score, total_marks, percent, passed, needs_review |
| `quiz_answers` | quiz_attempt_id, question_id, response (json), is_correct, marks_awarded, feedback |
| `assignments` | course_id, course_module_id, batch_id, title, brief, checklist (json), due_at, max_marks, allow_late, is_project, is_published |
| `submissions` | assignment_id, user_id, files (json), repository_url, demo_url, notes, submitted_at, is_late, status, marks, feedback, evaluated_by, evaluated_at |
| `certificates` | user_id, course_id, batch_id, number, title, issued_at, final_percent, grade, verification_code, pdf_path, issued_by, revoked_at, revoked_reason |
| `announcements` | course_id, batch_id, author_id, title, body, audience (`batch`/`course`), is_pinned, published_at |
| `leaderboard_points` | user_id, course_id, batch_id, source, reason (morph), points, awarded_at — unique per student, source and reason |
| `activity_logs` | user_id, course_id, batch_id, action, subject (morph), meta (json), occurred_at |
| `student_warnings` | user_id, batch_id, live_session_id, level (`notice`/`warning`/`escalation`), reason, private_note, issued_by, acknowledged_at, guardian_notified_at, guardian_contact, resolved_at |

An internship shares the `courses` table rather than getting its own, because
structurally it is the same object: a cohort with a schedule, a syllabus, a mentor and an
assessment. What differs is the paperwork it produces, which is why `documents_provided`
and the `internship_documents` table exist.

`courses.price` and `courses.sale_price` are **paise**, like every other money column.
They were rupees until Phase 5, and were converted in a migration, because a course fee
that reaches the same invoice machinery as everything else cannot be the one figure held
in a different unit.

Four columns carry the whole locking scheme, and a lesson passes only if all of them
allow it: `unlock_after_days` or `unlock_at` (counted from the batch start, or the
enrolment when there is no batch), `prerequisite_lesson_id`, `requires_payment` against
`enrollments.has_paid`, and `required_quiz_id` with `min_quiz_score`. `is_preview`
overrides the fee, because a sample nobody can open sells nothing.

`quiz_attempts.expires_at` is written when the attempt starts and is what decides whether
a submission is marked. The countdown on the page is a courtesy; a timer the browser owns
is a timer the browser can stop.

`leaderboard_points` is unique on student, source and reason, so the same lesson cannot
be paid for twice. A leaderboard that can be farmed is not measuring anything.

The warning table is deliberately auditable. Escalating a student is a serious act, so who
issued it, why, and whether the student acknowledged it are all recorded.

## Money (Phases 4 and 5, shared; built in Phase 4)

One ledger serves both business lines, so reporting does not have to union two systems.

| Table | Key columns |
| --- | --- |
| `payment_requests` | user_id, payable (project/milestone/course/subscription/api plan), title, amount, tax, total, due_at, status, raised_by, notes |
| `invoices` | user_id, number, financial_year, payment_request_id, subtotal, tax_breakup (json), total, status, issued_at, due_at, pdf_path |
| `invoice_items` | invoice_id, description, hsn_sac, quantity, unit_price, tax_rate, amount |
| `transactions` | user_id, invoice_id, gateway, gateway_order_id, gateway_payment_id, amount, currency, status, method, paid_at, failure_reason, receipt_path |
| `refunds` | transaction_id, amount, reason, status, processed_at |
| `coupons` | code, type, value, applies_to, usage_limit, used_count, valid_from, valid_until |

Both the client transactions screen and the student transactions screen read this same
ledger, filtered by user. Both print and download from the same PDF renderer.

## Chat (Phase 6)

| Table | Key columns |
| --- | --- |
| `conversations` | type (`client_direct`/`batch_group`), client_id, project_id, batch_id, title, last_message_at, last_message_preview, is_archived |
| `conversation_participants` | conversation_id, user_id, role (`member`/`staff`), joined_at, last_read_at, is_muted, left_at — unique per conversation and person |
| `messages` | conversation_id, sender_id, kind (`text`/`image`/`audio`), body, media_path, media_mime, media_size, media_meta (json), reply_to_id, sent_at, edited_at, deleted_at |
| `message_receipts` | message_id, user_id, delivered_at, read_at — unique per message and person |

`kind` is an enum, so the text, image and audio rule is enforced by the database and not
only by whichever controller happens to be writing. A future code path that wants to
attach a PDF fails loudly rather than quietly succeeding.

`last_message_at` and `last_message_preview` are denormalised onto the conversation so a
list of threads can be ordered and previewed without a query per row. They are written by
the same service that writes the message.

Unread is derived from `conversation_participants.last_read_at` rather than counted from
receipts: one column beats one query per message, and "since I last looked" is what
somebody means by unread. Receipts answer the other question — whether a particular
person has seen a particular message — which is what the ticks are for.

`left_at` rather than a deleted row: somebody who drops a batch stops receiving messages
and keeps what they already read, so a conversation they were part of does not silently
rewrite itself.

A message is soft deleted and rendered as "this message was removed". A hole where a
message used to be is worse than an honest gap, especially when a reply quotes it. The
media file is deleted for real at the same moment.

`media_meta` carries an image's dimensions, and for audio the duration and the waveform
peaks. The peaks are computed once, in the browser that recorded it, because there is no
ffmpeg on the server and every viewer redrawing them from the file would be the same work
repeated.

## Platform (all phases)

| Table | Key columns |
| --- | --- |
| `notifications` | Laravel default, plus channel fan out to mail, SMS and FCM |
| `devices` | user_id, token, platform, app_version, last_seen_at |
| `settings` | key, value (json), group |
| `message_templates` | key, channel, subject, body, variables (json) |
| `audit_logs` | actor_id, action, subject_type, subject_id, changes (json), ip |
| `saved_views` | user_id, screen, name, state (json), is_shared, times_used, last_used_at |
| `report_schedules` | user_id, report, cadence, day, hour, filters (json), recipients (json), format, is_active, last_sent_at, last_error |

A saved view stores the **query, not the rows** — the same query the URL already
carries — so applying one and following a link a colleague sent are the same operation,
and the answer is always current. `screen` is a path on this platform and is checked to
be one, because a stored URL is a URL somebody will later click.

A schedule's recipients are **addresses rather than accounts**: the person who needs the
monthly numbers is often an accountant or a college coordinator with no login here. Each
one decides for itself whether it is due, checked against `last_sent_at` rather than the
clock alone, so an hourly worker that runs twice does not send twice and one that was
down all morning still sends once when it comes back. A failure is written to
`last_error` rather than swallowed: nobody notices a report that quietly stopped
arriving until they need it.

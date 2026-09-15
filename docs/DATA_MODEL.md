# Data model overview

Indicative schema for the phases in `PROJECT_PLAN.md`. Column lists are abbreviated to the
fields that carry meaning. Every table gets `id`, timestamps, and soft deletes where
history matters.

## Identity (Phase 1)

| Table | Key columns |
| --- | --- |
| `users` | name, email, mobile, password, role (`admin`/`client`/`student`), status, avatar, email_verified_at, mobile_verified_at, must_change_password, last_login_at |
| `user_profiles` | user_id, company, designation, address, city, state, pincode, gstin, dob, guardian_name, guardian_mobile |
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
| `leads` | name, email, mobile, company, message, source_page, solution_id, course_id, status, assigned_to, converted_user_id |
| `testimonials`, `faqs`, `pages` | content managed from admin |

## Client module (Phase 4)

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
| `courses` | title, slug, type (`course`/`programme`), summary, description, level, duration_weeks, price, visibility (`public`/`lms_only`), thumbnail, syllabus (json), is_published |
| `course_modules` | course_id, title, order, unlock_rule (json) |
| `lessons` | module_id, title, order, content, video_url, duration_minutes, unlock_at, prerequisite_lesson_id, requires_payment, min_quiz_score |
| `materials` | lesson_id, course_id, title, path, mime, size, is_downloadable |
| `batches` | course_id, name, code, trainer_id, starts_on, ends_on, capacity, schedule (json), status |
| `enrollments` | user_id, course_id, batch_id, status, enrolled_at, completed_at, progress_percent, source |
| `live_sessions` | batch_id, lesson_id, title, scheduled_at, duration_minutes, meet_link, calendar_event_id, status |
| `attendances` | live_session_id, user_id, status (`present`/`absent`/`late`), marked_by, marked_at, note |
| `quizzes` | course_id, module_id, title, time_limit_minutes, attempts_allowed, pass_percent, shuffle |
| `questions` | quiz_id, type (`mcq`/`multi`/`truefalse`/`short`), body, options (json), correct (json), marks, explanation |
| `quiz_attempts` | quiz_id, user_id, started_at, submitted_at, score, percent, passed |
| `quiz_answers` | attempt_id, question_id, response (json), is_correct, marks_awarded |
| `assignments` | course_id, module_id, title, brief, due_at, max_marks |
| `submissions` | assignment_id, user_id, files (json), notes, submitted_at, marks, feedback, evaluated_by |
| `certificates` | user_id, course_id, batch_id, number, issued_at, verification_code, pdf_path |
| `announcements` | course_id, batch_id, title, body, published_at, audience |
| `leaderboard_points` | user_id, course_id, batch_id, source, points, awarded_at |
| `activity_logs` | user_id, subject, action, meta (json), occurred_at |
| `student_warnings` | user_id, batch_id, live_session_id, level (`notice`/`warning`/`escalation`), reason, issued_by, acknowledged_at, guardian_notified_at |

The warning table is deliberately auditable. Escalating a student is a serious act, so who
issued it, why, and whether the student acknowledged it are all recorded.

## Money (Phases 4 and 5, shared)

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
| `conversations` | type (`client_direct`/`batch_group`), client_id, project_id, batch_id, title, last_message_at |
| `conversation_participants` | conversation_id, user_id, role, joined_at, last_read_at, muted |
| `messages` | conversation_id, sender_id, kind (`text`/`image`/`audio`), body, media_path, media_meta (json), reply_to_id, sent_at |
| `message_receipts` | message_id, user_id, delivered_at, read_at |

`kind` is constrained to text, image and audio at the database level, so the image and
audio only rule cannot be bypassed by a future code path.

## Platform (all phases)

| Table | Key columns |
| --- | --- |
| `notifications` | Laravel default, plus channel fan out to mail, SMS and FCM |
| `devices` | user_id, token, platform, app_version, last_seen_at |
| `settings` | key, value (json), group |
| `message_templates` | key, channel, subject, body, variables (json) |
| `audit_logs` | actor_id, action, subject_type, subject_id, changes (json), ip |
| `saved_views` | user_id, screen, name, filters (json), is_shared |

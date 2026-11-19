# The platform API, for the apps

This is what the developers of the Unboundbyte apps read. The contract itself is
[`docs/api/openapi.yaml`](api/openapi.yaml), an OpenAPI 3.1 document: import it into
Postman, Insomnia or Swagger UI to browse and try every endpoint. This page explains
how the pieces fit together.

The apps are planned for after the website goes live (decision X25 in
`docs/EXPANSION_PLAN.md`). The API is ready for them now: versioned, documented and
checked against this document on every test run.

## The rules that do not change

- **Everything is under `/api/v1`.** Production is `https://unboundbyte.com/api/v1`.
- **Within v1, a field is only ever added.** Nothing is renamed, retyped or removed, and
  a new endpoint never changes an old one. Ignore fields you do not know. A change that
  would break an app is `/api/v2`, and v1 keeps running beside it until the apps have
  moved.
- **Every property the document lists is always there,** with `null` where its type
  allows it, unless the document says it may be absent.
- **Send `Accept: application/json`** with every request, so an error comes back as JSON.

## Signing in

One account per person, for all three roles; `role` in the user object says which app
screens to show. Every sign in on the web and in the app goes through the same checks:
a suspended account is refused, two-step sign in is asked for when it is on, and the
sign in is recorded in the person's security log.

1. `POST /auth/login` with `identifier` (email or mobile), `password` and the device's
   details, or `POST /auth/code` and then `POST /auth/code/verify` to sign in with a code
   sent by email or SMS.
2. The answer is either:
   - **`200`** with `token`, `must_change_password` and the user. Keep the token in the
     platform's secure storage (Keychain, Keystore).
   - **`202`** with `two_factor_required` and a `challenge_token`, good for ten minutes.
     Send the code from the person's authenticator app, or a recovery code, to
     `POST /auth/two-factor` with that token; the answer is the `200` above.
3. Send the token on every later request as `Authorization: Bearer <token>`.

The device's details are `device_name` (shown in the person's list of devices, such as
"Priya's Pixel 9"), and optionally `device_identifier` (stable for the installation),
`platform` (`ios`, `android` or `web`) and `app_version`. Signing in again with the same
`device_identifier` replaces that device's token instead of adding another.

An unknown account and a wrong password get the same answer, and asking for a code says
the same thing whether or not the account exists. Do not try to tell them apart.

### A temporary password

An account made by our staff starts on a temporary password. Until it is replaced,
every endpoint except `GET /me`, `POST /auth/password` and signing out answers **`423`**
with `"code": "password_change_required"`. Show the screen for choosing a password and
send `POST /auth/password` with `current_password`, `password` and
`password_confirmation`. This device stays signed in; every other device is signed out.

### Tokens and devices

- Tokens do not expire on a timer. `POST /auth/refresh` swaps the token for a new one
  (the old one stops at once); do it now and then, for instance on each app update.
- `GET /devices` lists the account's devices, this one marked `current`;
  `DELETE /devices/{id}` signs one out. `POST /auth/logout` signs this device out and
  `POST /auth/logout-all` every device.
- A `401` means the token is gone: signed out, refreshed or never valid. Go back to the
  sign in screen.

## Errors

| Status | Meaning | Body |
|---|---|---|
| `401` | No token, or a dead one | `message` |
| `403` | Not for this account, such as a student asking for client records or a sealed lesson being marked done; a suspended account at sign in (`code: account_suspended`) | `message`, sometimes `code` |
| `404` | Not found, or not this account's. The two are answered the same on purpose. | `message` |
| `422` | Something sent was not acceptable | `message`, and `errors` by field |
| `423` | A temporary password (`code: password_change_required`), or a sealed lesson (its `lock`) | see the document |
| `429` | Too many tries; wait `retry_after` seconds or the `Retry-After` header | `message` |

A message is written to be shown to the person as it is.

## Reading the answers

- **Money** comes as a string ready to show, such as `₹49,999.00`. Where an amount is a
  number it is in paise and its name ends in `Minor`. Never do arithmetic on the string.
- **Times** are ISO 8601 with the offset, in India time (`2026-11-19T18:30:00+05:30`);
  dates are `YYYY-MM-DD`.
- **Names.** The sign in answers and the user object use `snake_case`; everything else
  uses `camelCase`. Both stay as they are within v1.
- **Lists** are capped (100 for most, 20 for past classes) rather than paged; the
  numbers involved are small. Chat messages come oldest first, 100 at a time: pass the
  last id you hold as `after` to get only what is new.

## Keeping a copy, and working offline

Every `GET` answer carries an `ETag`. Keep the answer and its ETag, and next time send
`If-None-Match: <etag>`: if nothing changed the answer is an empty **`304`**, which
costs almost nothing on a phone network. Show the kept copy while offline and say when
it was last brought up to date.

## Files

Documents, invoices, receipts, lesson handouts and chat pictures and voice notes are
fetched from the URL the API gives (`downloadUrl`, `pdfUrl`, `receiptUrl`, `mediaUrl`),
with the same `Authorization` header. A chat `mediaUrl` is also signed and lasts 30
minutes: fetch the messages again for a fresh one. A file somebody else's account owns
is a `404`.

## Lessons that are not open yet

A lesson can be sealed: by its date, by a lesson that has to be finished first, by the
fee, or by a quiz score. The course answer gives every lesson a `lock`; a sealed one has
`open: false`, a `reason` written to show the student, and for a date, `opensAt`. Opening
a sealed lesson answers `423` with the lesson's title and its `lock`, never the content.
Show the reason; do not hide the lesson.

## Chat

Rooms come from `GET /chat`; a room's messages from `GET /chat/{id}/messages`. Send text,
a picture or a voice note to the same path (`multipart/form-data` for a file). Mark a
room read with `POST /chat/{id}/read` when the person has seen it. Until push and
realtime reach the apps, poll the open room every few seconds with `after`, and the room
list when the app comes to the front.

## Push notifications

Register the device's push token with `POST /devices/push-token` (`push_token`,
`platform`) after signing in and whenever the platform gives a new one. Tokens are kept
against the device now; notifications are sent to them once the apps are released and a
Firebase Cloud Messaging project is set up. They will carry the same `title` and `url`
the web shows under the bell.

## Rate limits

Signing in, codes and the two-step step are limited per identifier and per address, and
a few actions such as sending chat messages are limited per account. A `429` says how
long to wait. The licence check (`POST /licences/check`) is for software we sold, not
for the apps; `docs/LICENCE_API.md` describes it.

## A sandbox

Use a staging copy of the platform (a subdomain with its own database), seeded with the
demo data: `php artisan migrate --seed` creates a client (`client@unboundbyte.test`) and
a student (`student@unboundbyte.test`) with projects, invoices, courses, classes and chat,
password `Password123!`. Demo accounts are never created in production.

## Changing the API

Every change to an endpoint changes `docs/api/openapi.yaml` in the same commit.
`tests/Feature/Api/ContractTest.php` fails if a route is missing from the document, if
the document names a route that does not exist, or if an answer has a field the document
does not list, lacks one it does, or has the wrong type. Adding a field means documenting
it; anything that would break an app is v2.

# EduPortal LMS - Advanced Learning Management System

**Developed by: [Alwin T. Casagan](https://casagan.vercel.app/)** | Web Developer

EduPortal is an Assignment Portal and Learning Management System (LMS) for educational institutions. It provides a fast, secure environment for academic collaboration between students and faculty.

---

## 🚀 Platform Overview

### 👨‍🏫 Faculty Control Center
- **Assignment Management**: Broadcast materials and instructions to specific grades, strands and sections.
- **Real-Time Grading**: Grade submissions and leave remarks from a centralized dashboard.
- **Data Portability**: Export submissions into ZIP archives for offline review.
- **In-App Notifications**: Students are notified the moment an assignment is published.

### 👨‍🎓 Student Learning Hub
- **Submission Engine**: Upload PDF and Word documents, with resumable chunked uploads for large files.
- **Academic Tracking**: View marks and teacher remarks instantly upon grading.
- **Unified Dashboard**: All active and completed assignments in one view.
- **Cross-Device Ready**: Installable PWA with offline shell and background sync.

---

## 🛠️ Technology Stack

- **Frontend**: HTML5, vanilla CSS design system, vanilla ES modules
- **Backend**: PHP 8.2+ (Apache / mod_php, also runs on Nginx + PHP-FPM)
- **Database**: PostgreSQL 13+ (primary), MySQL 5.7+ / MariaDB 10.4+ supported
- **Object storage**: Cloudflare R2 or any S3-compatible endpoint (resumable uploads)
- **Queue worker**: `worker.php` for background export, upload cleanup and notifications
- **Deployment**: Docker on Render (`render.yaml`)

---

## 📂 Project Structure

```text
EduPortal/
├── student/             # Student dashboard, login, signup
├── teacher/             # Faculty dashboard, grading, assignment posting
├── config/              # database.php (core engine) + local credentials.php
├── controllers/         # Request handlers (one per action)
├── libs/                # assignment_management, NotificationManager, teacher_account
├── src/                 # ObjectStorageService (S3/R2 client)
├── migrations/          # Idempotent schema migrations + run.php
├── tests/               # Lightweight test suite
├── data/                # Schema (eduportal_*.sql) and dev-only seed
├── assets/              # Design system, JS modules, images
├── uploads/             # Local file storage (development / small deployments)
├── worker.php           # Background task runner
├── Dockerfile
├── pwa-apache.conf      # Apache hardening shipped inside the image
└── render.yaml
```

---

## ⚙️ Installation & Setup

### 1. Requirements
- **PHP**: 8.1 or higher, with `pdo_pgsql` (or `pdo_mysql`), `fileinfo`, `zip`
- **Database**: PostgreSQL 13+ recommended
- **Composer**: only required for the optional resumable-upload feature (`aws/aws-sdk-php`)
- **Web server**: Apache with `mod_rewrite`, or Nginx

### 2. Database schema

Choose the schema matching your engine:

| Engine | File |
|---|---|
| PostgreSQL | `data/eduportal_postgresql.sql` |
| MySQL / MariaDB | `data/eduportal_final.sql` |

Optional development accounts (published passwords) live in `data/dev_seed.sql`.
**Never apply that file to a production database** — it is gitignored for this reason.

### 3. Configuration

Set real environment variables (recommended), or copy `.env.example` to
`config/credentials.php` for local development. `config/credentials.php` is
gitignored and excluded from the Docker build context.

Key variables:

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME` | Database connection |
| `DB_SSL_MODE` | `require` for managed PostgreSQL; `verify-full` with `DB_SSL_ROOTCERT` for certificate pinning |
| `S3_BUCKET`, `S3_ENDPOINT`, `S3_ACCESS_KEY`, `S3_SECRET_KEY` | Resumable uploads (optional) |
| `EDUPORTAL_ALLOW_RUNTIME_MIGRATIONS` | `0` in production, `1` for local auto-migration |
| `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET_KEY` | reCAPTCHA v3 key pair. Both required; with either missing the feature switches itself off |
| `RECAPTCHA_ENABLED` | `0` is the kill switch for the whole feature, no redeploy needed |
| `RECAPTCHA_GATE_MODE` | `enforce` / `observe` / `off` for the first-visit check |
| `HUMAN_GATE_SECRET` | Optional. Lets a verified browser stay verified for 30 days instead of one session |
| `GA_MEASUREMENT_ID` | Google Analytics 4 property. Defaults to `G-JZ0E3M8PPD` |
| `GA_ENABLED` | `0` to emit no analytics tag — set this locally so dev traffic stays out of production numbers |

### 4. Migrations

Schema changes are a deploy-time concern. Run them as a release step, before the
new code starts serving traffic:

```bash
php migrations/run.php            # apply all
php migrations/run.php --status   # list without running
```

Controllers only *report* schema readiness; they never run `ALTER TABLE` in
production. Locally you can set `EDUPORTAL_ALLOW_RUNTIME_MIGRATIONS=1` to let
them self-heal a scratch database.

### 5. Permissions

`uploads/` must be writable by the web server, and must **not** be web-readable
if files are served by PHP. Both `pwa-apache.conf` and the root `.htaccess`
deny direct access to `config/`, `data/`, `migrations/` and `uploads/`.

### 6. Background worker

```bash
php worker.php cleanup_uploads       # abort expired multipart uploads
php worker.php cleanup_notifications # prune old notifications
php worker.php cleanup_all
```

### 7. Testing

```bash
composer test     # or: php tests/assignment_management_test.php
php tests/upload_helpers_test.php
php tests/recaptcha_service_test.php
php tests/analytics_test.php
```

---

## 🔒 Security Model

- **CSRF**: state-changing endpoints require a POST plus a per-session token.
  Tokens are sent in the `X-CSRF-Token` header, never in a query string.
- **Sessions**: regenerated on login and bound to IP + User-Agent. Note that
  `verifySessionBinding()` in `config/database.php` compares a stored
  fingerprint written once at login; the current IP is never trusted from the
  session itself.
- **Teacher approval**: self-service teacher registration creates a `pending`
  account. Until an administrator sets `teachers.status = 'approved'`, login is
  refused — teacher visibility is scoped by subject, so an unapproved account
  would otherwise expose every submission in that subject.
- **Authorization**: student-facing download predicates are scoped to the
  submitting student; teacher-facing predicates match `teacher_id` first and
  fall back to a subject **and** class match for legacy rows.
- **Upload validation**: extension allowlist, `finfo` content sniffing against
  the *assembled* object, and a size check against the declared size.
- **Path traversal**: every filesystem read is `realpath()`-resolved and
  re-checked against the uploads base directory.
- **reCAPTCHA v3**: the public auth pages (both logins, both signups and the
  password-reset request) refuse any request without a valid, single-use
  token, checked *before* the credential probe, the rate limiter or the
  database. See the section below.
- **Google Analytics**: a GA4 tag in `<head>` on every user-facing page, with
  no student identifiers attached. See the section below.

---

## 🛡️ reCAPTCHA v3

Two controls, deliberately not the same kind of control.

### Form checks — a hard control

| Action | Applies to |
|---|---|
| `login` | `student/login.php`, `teacher/login.php` |
| `signup` | `student/signup.php`, `teacher/signup.php` |
| `password_reset_request` | `forgot_password.php` |

A request with no token, a replayed token, a token minted for a different
action, or a token minted on a different host is refused **before** the
password check, the throttler, the uniqueness probe or the mail relay. The
ordering is the point: those are the operations a bot farm wants, and a token
that is merely logged afterwards protects nothing.

Three properties are worth knowing:

- **A missing token always fails closed.** The only thing
  `RECAPTCHA_FAIL_MODE` governs is a failure to *reach* Google. That defaults
  to open, because a Google outage must not lock an entire school out of its
  portal — but absence of a token is not an outage, it is what a bot does.
- **The hostname is checked.** The site key is public, so a token can be
  minted for any action from any site. The hostname Google reports is the only
  thing binding it back to this deployment, so a mismatch is a rejection.
- **Failures are one message.** Whether the token was missing, replayed or
  scored low, the user sees the same text. Distinguishing them tells an
  attacker which half of the defence to work on.

`reset_password.php` is deliberately excluded. Reaching it already required a
single-use token mailed to the account holder, and a second failure mode on
the only account-recovery path is a bad trade.

### First-visit gate — a friction control, not an access control

A brand new browser sees a branded "verifying you are human" screen before the
site opens, then is marked verified and never asked again (for the session, or
for 30 days when `HUMAN_GATE_SECRET` is set — the receipt is an HttpOnly
cookie signed with that secret, because a cookie written by script proves
nothing).

Be clear about what this is: it costs an attacker one verified round trip to
Google per cold browser, which is what makes a scripted signup farm expensive
to operate. It is **not** access control — anyone can clear or forge a cookie,
and with JavaScript disabled there is no gate at all. The controls that have
to hold are the server-side token checks above, which run whether or not
anything in this gate ever executed.

It is also built so it can never take the portal down:

- A hard 15-second client deadline opens the site whatever happened.
- Only a **low score** is treated as a judgement about the visitor. Every
  other refusal — Google unreachable, key and secret mismatched, hostname not
  registered in the reCAPTCHA console — is a fault, and the site opens rather
  than showing a whole school a "you are not human" screen.
- `RECAPTCHA_GATE_MODE=observe` downgrades the gate to a logged no-op without a
  redeploy, which is the move if a shared campus NAT address starts producing
  false positives.
- `RECAPTCHA_ENABLED=0` switches the whole feature off.

`RECAPTCHA_GATE_MIN_SCORE` defaults lower than the form threshold on purpose: a
false positive at the gate denies someone the entire portal, not one retry.

### Setup

1. Create a **reCAPTCHA v3** key in the Google admin console.
2. **Register the hostname.** This is the step that is easy to miss, and
   without it Google returns `browser-error` for every request and the portal
   looks broken. Register `localhost` (and the port) too, or set
   `RECAPTCHA_ENABLED=0` on the dev machine.
3. Set `RECAPTCHA_SITE_KEY` and `RECAPTCHA_SECRET_KEY`. The secret is
   gitignored and must never be committed. `config/credentials.php` may
   `define()` either name instead.
4. Optionally set `HUMAN_GATE_SECRET` to
   `bin2hex(random_bytes(32))`.

`.env.example` documents every knob, including the per-action score overrides
(`RECAPTCHA_MIN_SCORE_SIGNUP=0.4`) and the HTTP timeout. The Content-Security-
Policy in `config/database.php` and `pwa-apache.conf` already permits Google's
script, frame and connect origins; the two copies must stay in sync or the
stricter or looser one wins depending on which component answered.

---

## 📈 Google Analytics 4

The Google tag (`G-JZ0E3M8PPD`) is in `<head>` on every page a human reads in
a browser. It is emitted by `google_analytics_tag()` in `libs/Analytics.php`,
which `config/database.php` loads — so "the tag is on every page" is structural
rather than a property of 20 files that nobody remembers to update.

**Deliberately not sent.** No `user_id`, no custom dimensions, nothing derived
from an LRN or an email address. A GA user_id here would put student
identifiers into a system that has none of the portal's own access controls,
retention policy or deletion path. Role-level reporting works without it, and
the default config sends `page_location` and `referrer` only.

**Deliberately not instrumented**, because adding a `<script>` to any of these
would be wrong rather than merely redundant:

| Skipped | Why |
|---|---|
| `download_guide.php` | Serves `application/vnd.ms-word` as a `.doc` attachment. A script tag ends up *inside* the download |
| `logout.php`, `controllers/{check_session,submit,manage_assignment,download,download_all}.php` | Redirects; there is no document |
| `controllers/ajax_*.php`, `webauthn_*.php`, `public_stats.php`, `upload_endpoint.php`, `process_job.php` | JSON bodies and file streams. A script in a JSON response is a parsing bug, and counting a download as a pageview inflates the numbers |
| `student/nav.php`, `teacher/nav.php` | Included partials. A tag here would double the one the page already emits |
| `controllers/download_assignment.php` **is** instrumented | It genuinely renders an HTML error page for a user |

`tests/analytics_test.php` pins all of that, including that no controller
carries the tag without also serving HTML. Without it, the natural response to
"a page is missing the tag" is to add it everywhere, and that is exactly how a
script ends up in a JSON body.

Two more things worth knowing:

- **`GA_ENABLED=0` on development machines.** Otherwise your own page views are
  recorded in the production property and quietly skew every number in it.
- GA4 sets its own cookies. If the school needs a consent banner or a
  do-not-track response, that is work to add here, not something the tag
  handles on its own.

---

## 📜 Intellectual Property & Disclaimer

This software is developed and maintained by **Alwin T. Casagan**. It is intended for educational purposes and institutional management.

© 2026 EduPortal LMS. All rights reserved. Developed by [Alwin T. Casagan](https://casagan.vercel.app/).

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

---

## 📜 Intellectual Property & Disclaimer

This software is developed and maintained by **Alwin T. Casagan**. It is intended for educational purposes and institutional management.

© 2026 EduPortal LMS. All rights reserved. Developed by [Alwin T. Casagan](https://casagan.vercel.app/).

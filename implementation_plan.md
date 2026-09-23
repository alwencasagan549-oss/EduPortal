# EduPortal LMS Implementation Plan

## 1. Code Implementation

### Fix 1: Move SMTP Credentials to Queue Worker (Issue 1)

**File:** `controllers\ajax_send_email.php`

**Old (lines 31–39):**
```php
    $payload = [
        'to' => $student['email'],
        'subject' => $subject,
        'message' => $message,
        'from_name' => $_SESSION['user_name'],
        'from_email' => $_SESSION['user_email'],
        'smtp_user' => SMTP_USER,
        'smtp_pass' => SMTP_PASS
    ];
```

**New:**
```php
    $payload = [
        'to' => $student['email'],
        'subject' => $subject,
        'message' => $message,
        'from_name' => $_SESSION['user_name'],
        'from_email' => $_SESSION['user_email']
    ];
```

**File:** `libs\QueueManager.php`

**Old (lines 63–73):**
```php
        try {
            if ($job['type'] === 'email') {
                require_once __DIR__ . '/SMTPMailer.php';
                $result = SMTPMailer::send(
                    $payload['to'],
                    $payload['subject'],
                    $payload['message'],
                    $payload['from_name'],
                    $payload['from_email'],
                    $payload['smtp_user'],
                    $payload['smtp_pass']
                );
```

**New:**
```php
        try {
            if ($job['type'] === 'email') {
                require_once __DIR__ . '/SMTPMailer.php';
                $smtp_user = getenv('SMTP_USER') ?: SMTP_USER;
                $smtp_pass = getenv('SMTP_PASS') ?: SMTP_PASS;
                $result = SMTPMailer::send(
                    $payload['to'],
                    $payload['subject'],
                    $payload['message'],
                    $payload['from_name'],
                    $payload['from_email'],
                    $smtp_user,
                    $smtp_pass
                );
```

---

### Fix 2: Fix EduPortalStmt::lastInsertId() (Issue 2)

**File:** `config\database.php`

**Old (lines 154–156):**
```php
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
```

**New:** Delete the three lines above. `QueueManager::push()` already uses `$conn->getPDO()->lastInsertId()`.

---

### Fix 3: Remove No-op EduPortalStmt::bind_param() (Issue 3)

**File:** `config\database.php`

**Old (lines 158–160):**
```php
    public function bind_param() {
        return true;
    }
```

**New:** Delete the three lines above.

---

### Fix 4: Correct submit.php Redirect Path (Issue 4)

**File:** `controllers\submit.php`

**Old (line 26):**
```php
    header('Location: student_dashboard.php?error=All+fields+are+required');
```

**New:**
```php
    header('Location: ../student/dashboard.php?error=All+fields+are+required');
```

---

### Fix 5: Fix EduPortalResult::num_rows() for PostgreSQL (Issue 5)

**File:** `config\database.php`

**Old (lines 163–181):**
```php
class EduPortalResult {
    private $stmt;

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function fetch_assoc() {
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetch_all($style = PDO::FETCH_ASSOC) {
        return $this->stmt->fetchAll($style);
    }

    public function num_rows() {
        return $this->stmt->rowCount();
    }
}
```

**New:**
```php
class EduPortalResult {
    private $stmt;
    private $cached_rows = null;

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function fetch_assoc() {
        if ($this->cached_rows !== null) {
            $row = array_shift($this->cached_rows);
            return $row === null ? false : $row;
        }
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetch_all($style = PDO::FETCH_ASSOC) {
        if ($this->cached_rows !== null) {
            return $this->cached_rows;
        }
        return $this->stmt->fetchAll($style);
    }

    public function num_rows() {
        if ($this->cached_rows === null) {
            $this->cached_rows = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return count($this->cached_rows);
    }
}
```

---

### Fix 6: Add finfo_file() MIME Verification (Issue 6)

**File:** `controllers\submit.php`

**Old (lines 87–88):**
```php
$file_content = base64_encode(file_get_contents($upload_path));
$file_type = mime_content_type($upload_path);
```

**New:**
```php
$file_content = base64_encode(file_get_contents($upload_path));
$file_type = mime_content_type($upload_path);

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$verified_mime = finfo_file($finfo, $upload_path);
finfo_close($finfo);

$allowed_mimes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

if ($verified_mime === false || !in_array($verified_mime, $allowed_mimes)) {
    unlink($upload_path);
    header('Location: student_dashboard.php?error=Invalid+file+type.+Please+upload+a+PDF+or+Word+document.');
    exit();
}

$file_type = $verified_mime;
```

---

### Fix 7: Log Swallowed Exceptions in submit.php (Issue 7)

**File:** `controllers\submit.php`

**Old (line 85):**
```php
} catch (Throwable $e) {}
```

**New:**
```php
} catch (Throwable $e) {
    error_log('EduPortal Submission Error: ' . $e->getMessage());
}
```

---

### Fix 8: Add CSRF Protection to logout.php (Issue 8)

**File:** `controllers\logout.php`

**Old (lines 1–31):**
```php
<?php
// logout.php
ob_start();
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Unset all session variables
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax'
    ]);
}

// Destroy the session
session_destroy();

// Clear buffer and redirect
ob_end_clean();
header('Location: index.php');
exit();
?>
```

**New:**
```php
<?php
// logout.php
ob_start();
require_once __DIR__ . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validate_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: index.php');
    exit();
}

// Unset all session variables
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax'
    ]);
}

// Destroy the session
session_destroy();

// Clear buffer and redirect
ob_end_clean();
header('Location: index.php');
exit();
?>
```

**UI Update Required:** Replace all `<a href="../logout.php" ...>` links with POST forms. Two patterns exist in the codebase.

**Pattern A (with JS confirmation):**
```html
<form method="POST" action="../logout.php" style="display:inline;" onsubmit="return EduPortal.confirmLogout(this)">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <button type="submit" class="logout-link">
        <i class="fas fa-right-from-bracket"></i> Logout
    </button>
</form>
```

**Pattern B (without JS confirmation):**
```html
<form method="POST" action="../logout.php" style="display:inline;">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <button type="submit" class="logout-link">
        <i class="fas fa-sign-out-alt"></i> Logout
    </button>
</form>
```

Files requiring this update: `student\dashboard.php`, `teacher\dashboard.php`, `teacher\contact_student.php`, `teacher\post_assignment.php`, `teacher\profile.php`, `teacher\students.php`, `student\assignments.php`.

---

### Fix 9: Unconditional Session Binding During Login (Issue 9)

**File:** `teacher\login.php`

**Old (lines 33–36):**
```php
                // Auth Shield: Regenerate Session for Security
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $teacher['id'];
```

**New:**
```php
                // Auth Shield: Regenerate Session for Security
                session_regenerate_id(true);
                bindSession();
                
                $_SESSION['user_id'] = $teacher['id'];
```

**File:** `student\login.php`

**Old (lines 33–36):**
```php
                // Auth Shield: Regenerate Session for Security
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $student['id'];
```

**New:**
```php
                // Auth Shield: Regenerate Session for Security
                session_regenerate_id(true);
                bindSession();
                
                $_SESSION['user_id'] = $student['id'];
```

---

### Fix 10: Normalize Path Separators in base_path() (Issue 10)

**File:** `config\database.php`

**Old (lines 195–198):**
```php
function base_path($path = '') {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $depth = substr_count($base, '\\') + substr_count($base, '/');
    return str_repeat('../', max(0, $depth - 0)) . ltrim($path, '/');
}
```

**New:**
```php
function base_path($path = '') {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $depth = substr_count(str_replace('\\', '/', $base), '/');
    return str_repeat('../', max(0, $depth - 0)) . ltrim($path, '/');
}
```

---

### Fix 11: Add Content-Security-Policy Header (Issue 11)

**File:** `config\database.php`

**Old (lines 66–71):**
```php
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}
```

**New:**
```php
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
}
```

> **Flag:** Inline `onmouseover`/`onmouseout` handlers in `teacher\login.php` (line 127) and `student\login.php` (line 124) will continue to work under the `'unsafe-inline'` bridge. Migrate these to external JavaScript event listeners or nonce-based inline scripts in a follow-up ticket.

---

### Fix 12: Sitemap Domain Verification (Issue 12)

**File:** `sitemap.xml`

**Old (lines 8–13):**
```xml
    <url>
        <loc>https://reesnhs.l.cd</loc>
        <lastmod>2026-09-16</lastmod>
        <changefreq>weekly</changefreq>
        <priority>1.0</priority>
    </url>
```

**Action:** No code change. Verify that `https://reesnhs.l.cd` is the intended canonical production domain. If the production domain differs, update all `<loc>` entries accordingly.

---

### Fix 13: Add CSRF Token Rotation (Issue 13)

**File:** `config\database.php`

**Old (lines 73–83):**
```php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token() {
    return $_SESSION['csrf_token'] ?? '';
}

function validate_csrf($token) {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
```

**New:**
```php
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_prev'] = '';
}

function csrf_token() {
    return $_SESSION['csrf_token'] ?? '';
}

function validate_csrf($token) {
    $current = $_SESSION['csrf_token'] ?? '';
    $previous = $_SESSION['csrf_token_prev'] ?? '';
    if (hash_equals($current, $token) || ($previous !== '' && hash_equals($previous, $token))) {
        return true;
    }
    return false;
}

function rotate_csrf() {
    $_SESSION['csrf_token_prev'] = $_SESSION['csrf_token'] ?? '';
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
```

**File:** `controllers\submit.php`

**Old (lines 13–18):**
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        header('Location: ../student/dashboard.php?error=Invalid+security+token');
        exit();
    }
}
```

**New:**
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        header('Location: ../student/dashboard.php?error=Invalid+security+token');
        exit();
    }
    rotate_csrf();
}
```

**File:** `controllers\ajax_send_email.php`

**Old (lines 8–13):**
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }
```

**New:**
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }
    rotate_csrf();
```

---

## 2. Step-by-Step Deployment Guide

### 2.1 Pre-deployment

1. **PostgreSQL Backup**
   ```bash
   pg_dump -U <db_user> -d <db_name> -F c -f edu_portal_backup_$(date +%Y%m%d).dump
   ```

2. **Verify Credential Source**
   - Confirm `config\credentials.php` exists, OR
   - Confirm environment variables `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_PORT`, `DB_SSL_MODE`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS` are set in the runtime environment.

3. **Verify Jobs Table Schema**
   ```sql
   SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'jobs' ORDER BY ordinal_position;
   ```
   Expected: `id`, `type`, `payload`, `status`, `error_message`, `created_at`, `updated_at`.

4. **Verify Uploads Directory**
   ```bash
   test -d uploads && chmod 0750 uploads || mkdir -m 0750 uploads
   ```
   Ensure the web-server user can write to `uploads/`.

5. **Verify PHP and Extensions**
   ```bash
   php -v
   php -m | grep -E "pdo_pgsql|fileinfo"
   ```
   Required: PHP 8.0+, `pdo_pgsql`, `fileinfo`.

### 2.2 Execution Order

Deploy in the following order to maintain dependency integrity:

1. **`config\database.php`** — Core engine changes: session binding, CSRF rotation, CSP header, base_path normalization, EduPortalResult cache fix, removal of dead `lastInsertId()` and `bind_param()`.
2. **`libs\QueueManager.php`** — SMTP credential resolution moved to worker runtime.
3. **`controllers\ajax_send_email.php`** — Remove `smtp_user`/`smtp_pass` from job payload.
4. **`controllers\submit.php`** — Redirect path correction, `finfo_file()` MIME verification, error logging, CSRF rotation hook.
5. **`controllers\logout.php`** — Enforce POST + CSRF token requirement.
6. **`teacher\login.php`** and **`student\login.php`** — Add `bindSession()` after `session_regenerate_id(true)`.
7. **View/sidebar files** — Replace logout anchor tags with POST forms in:
   - `student\dashboard.php`
   - `teacher\dashboard.php`
   - `teacher\contact_student.php`
   - `teacher\post_assignment.php`
   - `teacher\profile.php`
   - `teacher\students.php`
   - `student\assignments.php`
8. **`sitemap.xml`** — Manual verification only; no code change required unless domain is incorrect.

### 2.3 Post-deployment Smoke Tests

| Test | Steps | Expected Result |
|------|-------|-----------------|
| Teacher Login | POST `teacher/login.php` with valid email, subject, password | Redirect to `teacher/dashboard.php`; `$_SESSION['user_id']` set |
| Student Login | POST `student/login.php` with valid LRN and password | Redirect to `student/dashboard.php`; `$_SESSION['user_id']` set |
| Assignment Upload | POST `controllers/submit.php` with valid PDF and CSRF token | Redirect to `student/dashboard.php?success=1`; file stored in `uploads/` |
| Email Queue | AJAX POST `controllers/ajax_send_email.php` with valid data and CSRF token | JSON response: `{"success":true,"job_id":<int>}` |
| Logout CSRF | GET `logout.php` | Redirect to `index.php`; session NOT destroyed |
| Logout CSRF | POST `logout.php` without token | Redirect to `index.php`; session NOT destroyed |
| Logout CSRF | POST `logout.php` with valid token | Session destroyed; redirect to `index.php` |
| Session Binding | Login, then change `HTTP_USER_AGENT` and reload a protected page | `isLoggedIn()` returns false; redirect to `session_expired.php` |
| Sitemap Domain | Open `sitemap.xml` in browser or fetch it | Confirm `https://reesnhs.l.cd` resolves to the intended production host |

## 3. Technical Justification and Impact Analysis

### Fix 1 — SMTP Credentials in Payload
**Root cause:** `ajax_send_email.php` serializes `SMTP_USER` and `SMTP_PASS` into the `jobs` table payload. This exposes credentials to any process with database read access and prevents credential rotation without re-queueing or downtime.
**Performance:** Moving resolution to the queue worker adds no client-visible latency; the worker already executes asynchronously.
**Scalability:** Resolving at worker time supports seamless credential rotation and multi-tenant deployments where different workers may use different SMTP configurations.
**Side effects:** None. The worker interface (`SMTPMailer::send`) signature is unchanged.

### Fix 2 — EduPortalStmt::lastInsertId()
**Root cause:** Copy-paste artifact from a `mysqli`-style wrapper; `EduPortalStmt` has no `$pdo` property, so calling `$this->pdo` triggers a fatal error if invoked.
**Performance:** Zero runtime cost.
**Scalability:** N/A.
**Side effects:** None. The only production caller (`QueueManager::push`) uses `$conn->getPDO()->lastInsertId()` directly.

### Fix 3 — EduPortalStmt::bind_param()
**Root cause:** Dead code left over from an incomplete PDO migration. The method unconditionally returns `true`, giving developers false confidence that parameter binding is occurring.
**Performance:** Removing it has zero runtime cost.
**Scalability:** N/A.
**Side effects:** None. No production code calls `bind_param()`.

### Fix 4 — submit.php Redirect Path
**Root cause:** The redirect target `student_dashboard.php` is hardcoded without accounting for the `controllers/` directory depth.
**Performance:** Negligible.
**Scalability:** N/A.
**Side effects:** None.

### Fix 5 — EduPortalResult::num_rows()
**Root cause:** The PDO PostgreSQL driver does not guarantee `rowCount()` for `SELECT` statements; it commonly returns `0`. The original implementation therefore breaks login flows and any row-count check on PostgreSQL.
**Performance:** A small fetch-all cost is introduced, but this is correctness-critical. The internal caching layer prevents double-fetching if `fetch_assoc()` or `fetch_all()` is called afterward.
**Scalability:** For very large result sets, consider migrating callers to `fetch_all()` + `count()` directly. For current login/auth queries (1–few rows), the overhead is negligible.
**Side effects:** None. The public API (`num_rows()`, `fetch_assoc()`, `fetch_all()`) is preserved.

### Fix 6 — finfo_file() MIME Verification
**Root cause:** Extension-based validation (`pathinfo()`) alone is trivially bypassed by renaming a file. The original code lacked magic-byte inspection.
**Performance:** Negligible for 10 MB files.
**Scalability:** N/A.
**Side effects:** Rejected uploads are deleted from `uploads/` before redirect, preventing orphan files.

### Fix 7 — Empty Catch Block
**Root cause:** An empty `catch (Throwable $e) {}` swallows database migration failures (e.g., `ALTER TABLE` errors), leaving the schema in an unknown state without any trace.
**Performance:** Negligible.
**Scalability:** N/A.
**Side effects:** None. Errors are now surfaced in the PHP error log for ops visibility.

### Fix 8 — Logout CSRF Protection
**Root cause:** `logout.php` performs a state-changing operation (session destruction) without verifying a CSRF token, making it vulnerable to cross-site request forgery.
**Performance:** Negligible.
**Scalability:** N/A.
**Side effects:** Any external bookmark, link, or image tag pointing to `logout.php` via GET will no longer destroy the session. This is intentional. All internal navigation must use the new POST forms.

### Fix 9 — Session Binding on Login
**Root cause:** The session fingerprint (`_ip_fingerprint`) was only set when empty, meaning it was populated on the first request after login rather than being bound to the authenticated user's environment at the moment of authentication. If the fingerprint was set during an unauthenticated state or with a different IP, subsequent `verifySessionBinding()` checks could behave unexpectedly.
**Performance:** Negligible.
**Scalability:** N/A.
**Side effects:** None. Session regeneration order is preserved.

### Fix 10 — base_path() Depth Calculation
**Root cause:** Windows/IIS paths may contain backslashes in `$_SERVER['SCRIPT_NAME']`. The original code counted backslashes and forward slashes separately, double-counting mixed paths and producing incorrect relative paths.
**Performance:** Negligible.
**Scalability:** N/A.
**Side effects:** None. The function now behaves consistently across Linux and Windows.

### Fix 11 — Content-Security-Policy Header
**Root cause:** Missing defense-in-depth header against XSS, clickjacking, and data injection attacks.
**Performance:** Negligible header overhead.
**Scalability:** N/A.
**Side effects:** The CSP includes `'unsafe-inline'` for `script-src` and `style-src` as a temporary bridge to accommodate existing inline event handlers and inline styles in `teacher/login.php` and `student/login.php`. These inline handlers must be migrated to external JavaScript or nonce-based scripts before removing `'unsafe-inline'` from `script-src`.

### Fix 12 — Sitemap Domain Verification
**Root cause:** `reesnhs.l.cd` is a URL-shortener-style domain, which is atypical for a production LMS sitemap. It may indicate a misconfiguration or a placeholder that was never updated.
**Performance:** N/A.
**Scalability:** N/A.
**Side effects:** If the domain is incorrect, search engines will index the wrong canonical URLs, harming SEO.

### Fix 13 — CSRF Token Rotation
**Root cause:** The original single-token design means a leaked token remains valid until the session expires. Without rotation, long-lived sessions accumulate exposure time.
**Performance:** Negligible.
**Scalability:** The dual-token approach (current + previous) supports concurrent tabs because the previous token remains valid for exactly one additional request, allowing all open tabs to rotate seamlessly without losing their next request.
**Side effects:** Frontend JavaScript that reads the CSRF token from the DOM for AJAX requests should refresh the token after a successful POST. The dual-token acceptance window provides a one-request safety net, but proactive DOM updates are recommended to avoid relying on the fallback.

<?php
/**
 * Shared authentication service.
 *
 * Before this file, student and teacher sign-in were two independent PHP pages
 * with near-identical CSRF, throttling and password logic that had drifted
 * apart (30s vs 60s cooldowns, different error strings). Any new credential
 * type -- passkey, TOTP -- would have become a third copy of that drift, so
 * the shared behaviour lives here and the pages only supply their form fields
 * and redirect target.
 *
 * Design notes worth knowing before editing:
 *
 *   - Throttling is keyed on the identifier the *client submitted*, never on
 *     a database-resolved account. Keying on the resolved row would leak
 *     whether an account exists through the lockout message.
 *   - The per-IP threshold is deliberately loose. A school campus egresses
 *     through a single NAT address, so a tight per-IP limit locks out an
 *     entire class when one student fat-fingers their password.
 *   - Audit and throttling fail *open* on an un-migrated schema, matching
 *     teacher_account_status(). Locking every account out because a deploy
 *     ran ahead of its migration would be worse than a degraded control.
 *
 * Every database timestamp written here is UTC via gmdate(). That keeps the
 * tables consistent regardless of the server's PHP timezone, and all expiry
 * comparisons are done in PHP rather than SQL for the same reason.
 */

define('AUTH_BCRYPT_COST', 12);
define('AUTH_TOKEN_TTL', 1800);
define('AUTH_EVENT_IDENTIFIER_MAX', 190);
define('AUTH_EVENT_DETAIL_MAX', 1000);

function auth_driver_name($conn): string
{
    try {
        if (method_exists($conn, 'getDriverName')) {
            return strtolower((string) $conn->getDriverName());
        }
    } catch (Throwable $exception) {
        return 'pgsql';
    }

    return 'pgsql';
}

/**
 * Whitelisted table lookup. Callers interpolate the result into SQL, so this
 * must never echo caller-controlled input.
 */
function auth_role_table(string $role): ?string
{
    switch ($role) {
        case 'student':
            return 'students';
        case 'teacher':
            return 'teachers';
        default:
            return null;
    }
}

function auth_table_exists($conn, string $table): bool
{
    static $known = [];

    $key = auth_driver_name($conn) . '|' . $table;
    if (array_key_exists($key, $known)) {
        return $known[$key];
    }

    try {
        $schemaExpression = auth_driver_name($conn) === 'mysql' ? 'DATABASE()' : 'current_schema()';
        $stmt = $conn->prepare(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = {$schemaExpression} AND table_name = ?"
        );
        $stmt->execute([$table]);
        $known[$key] = $stmt->fetchColumn() !== false;
    } catch (Throwable $exception) {
        error_log('EduPortal auth table probe failed: ' . $exception->getMessage());
        $known[$key] = false;
    }

    return $known[$key];
}

/**
 * Cached column probe, so callers can feature-detect an optional column
 * instead of hard-failing on a schema that predates its migration.
 */
function auth_column_exists($conn, string $table, string $column): bool
{
    static $known = [];

    $key = auth_driver_name($conn) . '|' . $table . '|' . $column;
    if (array_key_exists($key, $known)) {
        return $known[$key];
    }

    try {
        $schemaExpression = auth_driver_name($conn) === 'mysql' ? 'DATABASE()' : 'current_schema()';
        $stmt = $conn->prepare(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = {$schemaExpression} AND table_name = ? AND column_name = ?"
        );
        $stmt->execute([$table, $column]);
        $known[$key] = $stmt->fetchColumn() !== false;
    } catch (Throwable $exception) {
        error_log('EduPortal auth column probe failed: ' . $exception->getMessage());
        $known[$key] = false;
    }

    return $known[$key];
}

function auth_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : '';
}

function auth_client_user_agent(): string
{
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return is_string($agent) ? substr($agent, 0, 255) : '';
}

/**
 * Interprets a timestamp read back from the auth tables. Values were written
 * as UTC by gmdate(), so a naive timestamp is reinterpreted as UTC rather
 * than as the server's local zone.
 */
function auth_parse_timestamp($value): ?int
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $hasZone = (bool) preg_match('/(UTC|GMT|[+-]\d{2}:?\d{2}|Z)$/i', $value);
    $timestamp = strtotime($hasZone ? $value : $value . ' UTC');

    return $timestamp === false ? null : $timestamp;
}

function auth_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------
// Audit trail
// ---------------------------------------------------------------------

/**
 * Appends a security event. Never throws: an audit write failure must not
 * turn a valid sign-in into a 500.
 */
function auth_record_event($conn, string $event, string $outcome, array $context = []): void
{
    if (!auth_table_exists($conn, 'auth_events')) {
        return;
    }

    $role = isset($context['user_role']) ? (string) $context['user_role'] : null;
    if ($role !== null && auth_role_table($role) === null) {
        $role = null;
    }

    $userId = isset($context['user_id']) && $context['user_id'] !== null
        ? (int) $context['user_id']
        : null;

    $identifier = isset($context['identifier']) && is_string($context['identifier'])
        ? substr($context['identifier'], 0, AUTH_EVENT_IDENTIFIER_MAX)
        : null;

    $detail = isset($context['detail']) && is_string($context['detail'])
        ? substr($context['detail'], 0, AUTH_EVENT_DETAIL_MAX)
        : null;

    $ip = $context['ip'] ?? auth_client_ip();
    $userAgent = $context['user_agent'] ?? auth_client_user_agent();

    try {
        $stmt = $conn->prepare(
            'INSERT INTO auth_events
                (event, outcome, user_role, user_id, identifier, ip_address, user_agent, detail, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            substr($event, 0, 48),
            substr($outcome, 0, 24),
            $role,
            $userId,
            $identifier,
            is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null,
            is_string($userAgent) && $userAgent !== '' ? substr($userAgent, 0, 255) : null,
            $detail,
            auth_now(),
        ]);
    } catch (Throwable $exception) {
        error_log('EduPortal auth audit write failed: ' . $exception->getMessage());
    }
}

// ---------------------------------------------------------------------
// Persistent throttling
// ---------------------------------------------------------------------

/**
 * Cooldown ladder per bucket kind. The index is how many thresholds have been
 * crossed, so the lockout escalates rather than resetting to a fixed delay.
 */
function auth_rate_limit_policy(string $kind): array
{
    if ($kind === 'ip') {
        // Loose on purpose: a campus is normally one NAT address.
        return ['threshold' => 25, 'cooldowns' => [60, 300, 900], 'window' => 900];
    }

    // Reset requests generate outbound mail, so they are throttled harder than
    // a password guess. Without this, the endpoint is a mail-bomb primitive
    // against whatever address is on file.
    if (str_starts_with($kind, 'reset')) {
        return ['threshold' => 3, 'cooldowns' => [300, 900, 3600], 'window' => 3600];
    }

    return ['threshold' => 5, 'cooldowns' => [30, 60, 300, 1800], 'window' => 1800];
}

function auth_rate_limit_bucket_key(string $kind, string $value): string
{
    return $kind . ':' . substr($value, 0, 180);
}

/**
 * The buckets checked for a login: the caller IP and the submitted account
 * identifier. Both are derived from the request, not from the database.
 */
function auth_login_buckets(string $role, string $identifier): array
{
    return [
        auth_rate_limit_bucket_key('ip', auth_client_ip()),
        auth_rate_limit_bucket_key($role, strtolower(trim($identifier))),
    ];
}

/**
 * Returns the number of seconds still locked, or null when the request may
 * proceed. If any bucket is locked the caller is blocked.
 */
function auth_rate_limit_check($conn, array $buckets): ?int
{
    if (!auth_table_exists($conn, 'auth_rate_limits') || $buckets === []) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($buckets), '?'));
    try {
        $stmt = $conn->prepare("SELECT bucket_key, locked_until FROM auth_rate_limits WHERE bucket_key IN ({$placeholders})");
        $stmt->execute(array_values($buckets));
        $result = $stmt->get_result();
    } catch (Throwable $exception) {
        error_log('EduPortal auth rate limit read failed: ' . $exception->getMessage());
        return null;
    }

    $now = time();
    $wait = null;
    while (($row = $result->fetch_assoc()) !== false) {
        $lockedUntil = auth_parse_timestamp($row['locked_until'] ?? null);
        if ($lockedUntil === null || $lockedUntil <= $now) {
            continue;
        }
        $remaining = $lockedUntil - $now;
        $wait = $wait === null ? $remaining : max($wait, $remaining);
    }

    return $wait;
}

/**
 * Pure decision function for the throttling ladder, separated from the SQL so
 * the arithmetic can be tested without a database.
 *
 * $row is the stored bucket row (attempt_count, window_started_at,
 * locked_until) or null when the bucket has never been touched.
 *
 * Returns null when the bucket is still serving an active cooldown and must
 * not be modified, otherwise the next attempt count, window start and lock
 * expiry to persist.
 */
function auth_rate_limit_next_state(?array $row, array $policy, int $now): ?array
{
    $attempts = 1;
    $windowStarted = $now;

    if (is_array($row)) {
        $currentLock = auth_parse_timestamp($row['locked_until'] ?? null);
        if ($currentLock !== null && $currentLock > $now) {
            return null;
        }

        $windowStart = auth_parse_timestamp($row['window_started_at'] ?? null) ?? $now;

        if (($now - $windowStart) > $policy['window']) {
            // Window elapsed without further failures: start fresh so an old
            // run of near-misses is not carried forward.
            $attempts = 1;
            $windowStarted = $now;
        } else {
            $attempts = ((int) ($row['attempt_count'] ?? 0)) + 1;
            $windowStarted = $windowStart;
        }
    }

    $lockedUntil = null;
    if ($attempts >= $policy['threshold']) {
        $ladder = $policy['cooldowns'];
        // min() with the last index is what stops the ladder running off the
        // end once an account is well past the final threshold.
        $step = min($attempts - $policy['threshold'], count($ladder) - 1);
        $lockedUntil = $now + $ladder[$step];
    }

    return ['attempts' => $attempts, 'window_started' => $windowStarted, 'locked_until' => $lockedUntil];
}

/**
 * Records one failure against every bucket and applies a cooldown once the
 * threshold is crossed.
 */
function auth_rate_limit_failure($conn, array $buckets): void
{
    if (!auth_table_exists($conn, 'auth_rate_limits') || $buckets === []) {
        return;
    }

    $now = time();

    foreach ($buckets as $bucket) {
        $kind = strpos($bucket, ':') === false ? 'account' : substr($bucket, 0, strpos($bucket, ':'));
        $policy = auth_rate_limit_policy($kind);

        $row = null;
        try {
            $stmt = $conn->prepare(
                'SELECT attempt_count, window_started_at, locked_until FROM auth_rate_limits WHERE bucket_key = ?'
            );
            $stmt->execute([$bucket]);
            $row = $stmt->get_result()->fetch_assoc();
        } catch (Throwable $exception) {
            error_log('EduPortal auth rate limit read failed: ' . $exception->getMessage());
        }

        $state = auth_rate_limit_next_state(is_array($row) ? $row : null, $policy, $now);
        if ($state === null) {
            // Still serving an active cooldown. Leave this bucket untouched so
            // repeated attempts cannot extend the lockout indefinitely, but
            // keep processing the other buckets.
            continue;
        }

        auth_rate_limit_write($conn, $bucket, $state['attempts'], $state['window_started'], $state['locked_until'], $now);
    }
}

function auth_rate_limit_write($conn, string $bucket, int $attempts, int $windowStarted, ?int $lockedUntil, int $now): void
{
    $lockedValue = $lockedUntil === null ? null : gmdate('Y-m-d H:i:s', $lockedUntil);

    try {
        if (auth_driver_name($conn) === 'mysql') {
            $sql = 'INSERT INTO auth_rate_limits (bucket_key, attempt_count, window_started_at, locked_until, updated_at)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        attempt_count = VALUES(attempt_count),
                        window_started_at = VALUES(window_started_at),
                        locked_until = VALUES(locked_until),
                        updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO auth_rate_limits (bucket_key, attempt_count, window_started_at, locked_until, updated_at)
                    VALUES (?, ?, ?, ?, ?)
                    ON CONFLICT (bucket_key) DO UPDATE SET
                        attempt_count = EXCLUDED.attempt_count,
                        window_started_at = EXCLUDED.window_started_at,
                        locked_until = EXCLUDED.locked_until,
                        updated_at = EXCLUDED.updated_at';
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $bucket,
            $attempts,
            gmdate('Y-m-d H:i:s', $windowStarted),
            $lockedValue,
            gmdate('Y-m-d H:i:s', $now),
        ]);
    } catch (Throwable $exception) {
        error_log('EduPortal auth rate limit write failed: ' . $exception->getMessage());
    }
}

/**
 * Clears the buckets that are safe to clear after a verified sign-in.
 *
 * The IP bucket is deliberately excluded by default: a shared campus address
 * must not have its distributed-attack counters reset because one student
 * remembered their password. Pass a different $keepKind, or null to clear
 * everything, when the caller knows the scope is legitimately narrow.
 */
function auth_rate_limit_clear($conn, array $buckets, ?string $keepKind = 'ip'): void
{
    if (!auth_table_exists($conn, 'auth_rate_limits') || $buckets === []) {
        return;
    }

    foreach ($buckets as $bucket) {
        $kind = strpos($bucket, ':') === false ? 'account' : substr($bucket, 0, strpos($bucket, ':'));
        if ($keepKind !== null && $kind === $keepKind) {
            continue;
        }

        try {
            $stmt = $conn->prepare('DELETE FROM auth_rate_limits WHERE bucket_key = ?');
            $stmt->execute([$bucket]);
        } catch (Throwable $exception) {
            error_log('EduPortal auth rate limit clear failed: ' . $exception->getMessage());
        }
    }
}

// ---------------------------------------------------------------------
// Passwords
// ---------------------------------------------------------------------

/**
 * Single source of truth for password rules. Previously signup required
 * 8 chars + uppercase + digit while the change-password path accepted 6
 * characters and no complexity at all.
 *
 * The 72-byte cap matters: bcrypt silently truncates at 72 bytes, so a longer
 * password would be verified against a prefix and the remainder would be
 * ignored.
 */
function auth_password_problem(string $password): ?string
{
    $length = strlen($password);

    if ($length < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if ($length > 72) {
        return 'Password must be 72 characters or fewer.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Password must include at least one digit.';
    }

    return null;
}

function auth_password_hash(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => AUTH_BCRYPT_COST]);
}

/**
 * Builds the password-reset UPDATE and its parameters in a single pass.
 *
 * The SET list is conditional, and assembling the SQL and the parameter array
 * separately let the two drift out of order: the account id was bound to the
 * timestamp column while the timestamp was bound to WHERE id. PostgreSQL
 * rejects that as a type error, so the reset silently failed with a generic
 * "could not be updated" rather than reporting a wrong-row update.
 *
 * Returning both together, with the id always appended last, makes the
 * mismatch unrepresentable.
 *
 * A teacher holds one row per subject, all sharing an email and a password,
 * and thinks of that as a single set of credentials. Resetting on one subject
 * only would leave the others on the old password, so a teacher reset is
 * applied across every row with that email.
 *
 * Students are deliberately NOT scoped this way. Their emails are explicitly
 * not unique -- siblings share a family mailbox -- so matching on email would
 * reset a sibling's password as a side effect.
 *
 * @return array{sql: string, params: array}
 */
function auth_password_reset_update(string $table, string $hashed, int $userId, bool $setVerified, ?string $scopeEmail = null): array
{
    $assignments = ['password = ?'];
    $params = [$hashed];

    if ($setVerified) {
        $assignments[] = 'email_verified_at = ?';
        $params[] = auth_now();
    }

    $scope = $scopeEmail === null
        ? 'WHERE id = ?'
        : 'WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))';

    $params[] = $scopeEmail ?? $userId;

    return [
        'sql' => 'UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . ' ' . $scope,
        'params' => $params,
    ];
}

/**
 * Rehashes on successful sign-in when the stored cost is below target.
 * The previous codebase never called password_needs_rehash(), so a cost
 * increase would never have reached existing accounts.
 */
function auth_upgrade_password_hash($conn, string $role, $userId, string $plain, string $hash): bool
{
    $table = auth_role_table($role);
    if ($table === null || !is_string($hash) || $hash === '') {
        return false;
    }

    if (!password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => AUTH_BCRYPT_COST])) {
        return false;
    }

    $replacement = auth_password_hash($plain);
    if (!is_string($replacement) || $replacement === '') {
        return false;
    }

    try {
        $stmt = $conn->prepare("UPDATE {$table} SET password = ? WHERE id = ?");
        $stmt->execute([$replacement, (int) $userId]);
    } catch (Throwable $exception) {
        error_log('EduPortal password upgrade failed: ' . $exception->getMessage());
        return false;
    }

    auth_record_event($conn, 'password_rehash', 'success', [
        'user_role' => $role,
        'user_id' => $userId,
    ]);

    return true;
}

// ---------------------------------------------------------------------
// Session establishment
// ---------------------------------------------------------------------

/**
 * Writes the post-login session. Both roles get the same lifecycle treatment:
 * ID regeneration, fingerprint binding, and the created/last-activity stamps
 * the idle timeout reads.
 */
function auth_establish_session(array $account, string $role): void
{
    session_regenerate_id(true);
    bindSession();

    $now = time();

    $_SESSION['user_id'] = $account['id'];
    $_SESSION['user_name'] = $account['name'] ?? '';
    $_SESSION['user_email'] = $account['email'] ?? '';
    $_SESSION['user_role'] = $role;
    $_SESSION['_created_at'] = $now;
    $_SESSION['_last_activity'] = $now;

    if ($role === 'student') {
        $_SESSION['user_lrn'] = $account['lrn'] ?? '';
        $_SESSION['user_grade'] = $account['grade_level'] ?? '';
        $_SESSION['user_section'] = $account['section'] ?? '';
        $_SESSION['user_strand'] = $account['strand'] ?? 'Academic';
    } elseif ($role === 'teacher') {
        $_SESSION['user_subject'] = $account['subject'] ?? '';
    }
}

function auth_logout_audit($conn): void
{
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    auth_record_event($conn, 'logout', 'success', [
        'user_role' => getUserRole(),
        'user_id' => $_SESSION['user_id'],
    ]);
}

// ---------------------------------------------------------------------
// Sign-in
// ---------------------------------------------------------------------

/**
 * The one sign-in path for both roles.
 *
 * $credentials is ['identifier' => string, 'password' => string] for students
 * (identifier is the LRN) and additionally 'subject' for teachers.
 *
 * Returns:
 *   ok       bool    whether a session should be created
 *   error    string  user-facing message, already generic
 *   outcome  string  audit outcome discriminator
 *   account  array   the database row, when ok is true
 */
function auth_attempt_password_login($conn, string $role, array $credentials): array
{
    $table = auth_role_table($role);
    if ($table === null) {
        return ['ok' => false, 'error' => 'Invalid credentials.', 'outcome' => 'invalid_request', 'account' => null];
    }

    $identifier = trim((string) ($credentials['identifier'] ?? ''));
    $subject = trim((string) ($credentials['subject'] ?? ''));
    $password = (string) ($credentials['password'] ?? '');

    // Buckets come from the submitted values, never from a resolved row, so
    // the lockout message cannot be used to probe for valid accounts.
    $buckets = auth_login_buckets($role, $role === 'student' ? $identifier : $identifier . '|' . $subject);

    $lockedFor = auth_rate_limit_check($conn, $buckets);
    if ($lockedFor !== null && $lockedFor > 0) {
        auth_record_event($conn, 'login', 'rate_limited', [
            'user_role' => $role,
            'identifier' => $identifier,
        ]);
        return [
            'ok' => false,
            'error' => 'Too many failed attempts. Please wait ' . $lockedFor . ' seconds.',
            'outcome' => 'rate_limited',
            'account' => null,
        ];
    }

    $account = $role === 'student'
        ? auth_find_student($conn, $identifier)
        : auth_find_teacher($conn, $identifier, $subject);

    $genericError = $role === 'student'
        ? 'Invalid LRN or Password.'
        : 'Invalid Email or Password.';

    $credentialsValid = $account !== null
        && is_string($account['password'] ?? null)
        && password_verify($password, $account['password']);

    if (!$credentialsValid) {
        auth_rate_limit_failure($conn, $buckets);
        auth_record_event($conn, 'login', 'failure', [
            'user_role' => $role,
            'identifier' => $identifier,
            'detail' => $role === 'teacher' && $subject !== '' ? 'subject=' . $subject : null,
        ]);

        $remaining = auth_rate_limit_check($conn, $buckets);

        return [
            'ok' => false,
            'error' => $remaining !== null && $remaining > 0
                ? 'Too many failed attempts. Please wait ' . $remaining . ' seconds.'
                : $genericError,
            'outcome' => 'failure',
            'account' => null,
        ];
    }

    // Credentials are correct from here on. An approval hold must not consume
    // a strike; the previous code handled this by decrementing the session
    // counter, which is no longer necessary now that throttling is persistent.
    if ($role === 'teacher') {
        require_once __DIR__ . '/teacher_account.php';

        $status = teacher_account_status($conn, $account['id']);
        if ($status !== 'approved') {
            auth_rate_limit_clear($conn, $buckets);
            auth_record_event($conn, 'login', 'blocked', [
                'user_role' => 'teacher',
                'user_id' => $account['id'],
                'identifier' => $identifier,
                'detail' => 'status=' . $status,
            ]);

            return [
                'ok' => false,
                'error' => teacher_account_status_message($status),
                'outcome' => 'blocked_' . $status,
                'account' => null,
            ];
        }
    }

    auth_upgrade_password_hash($conn, $role, $account['id'], $password, $account['password']);
    auth_rate_limit_clear($conn, $buckets);
    auth_record_event($conn, 'login', 'success', [
        'user_role' => $role,
        'user_id' => $account['id'],
        'identifier' => $identifier,
    ]);

    return ['ok' => true, 'error' => '', 'outcome' => 'success', 'account' => $account];
}

function auth_find_student($conn, string $lrn): ?array
{
    if ($lrn === '') {
        return null;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT id, lrn, name, email, grade_level, section, strand, password
             FROM students WHERE lrn = ?'
        );
        $stmt->execute([$lrn]);
        $account = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $exception) {
        error_log('EduPortal student lookup failed: ' . $exception->getMessage());
        return null;
    }

    return is_array($account) ? $account : null;
}

function auth_find_teacher($conn, string $email, string $subject): ?array
{
    if ($email === '' || $subject === '') {
        return null;
    }

    try {
        // Normalised on both sides, matching the signup and profile checks.
        // Sign-in was previously an exact match on email, so a teacher
        // registered as Teacher@school.com could not sign in as
        // teacher@school.com -- the form accepted the case, the login refused
        // it.
        $stmt = $conn->prepare(
            'SELECT id, name, email, subject, password
             FROM teachers
             WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
               AND LOWER(TRIM(subject)) = LOWER(TRIM(?))'
        );
        $stmt->execute([$email, $subject]);
        $account = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $exception) {
        error_log('EduPortal teacher lookup failed: ' . $exception->getMessage());
        return null;
    }

    return is_array($account) ? $account : null;
}

// ---------------------------------------------------------------------
// Single-use tokens (password reset, email ownership)
// ---------------------------------------------------------------------

/**
 * Returns the raw token. Only its SHA-256 is stored, so a database dump does
 * not let an attacker reset anyone's password. Any unused token for the same
 * (purpose, role, user) is revoked first, so a freshly requested link always
 * supersedes an older emailed one.
 */
function auth_issue_token($conn, string $purpose, string $role, $userId): ?string
{
    if (auth_role_table($role) === null) {
        return null;
    }
    if (!auth_table_exists($conn, 'auth_tokens')) {
        return null;
    }

    $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash = hash('sha256', $raw);

    try {
        auth_revoke_tokens($conn, $purpose, $role, $userId);

        $stmt = $conn->prepare(
            'INSERT INTO auth_tokens (token_hash, purpose, user_role, user_id, expires_at, request_ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $hash,
            $purpose,
            $role,
            (int) $userId,
            gmdate('Y-m-d H:i:s', time() + AUTH_TOKEN_TTL),
            auth_client_ip() ?: null,
            auth_now(),
        ]);
    } catch (Throwable $exception) {
        error_log('EduPortal auth token issue failed: ' . $exception->getMessage());
        return null;
    }

    return $raw;
}

/**
 * Validates and atomically consumes a token. Returns the token row on success,
 * or null for an unknown, already-used, or expired token. A used token is
 * rejected even if it has not expired, which is what makes a replayed reset
 * link inert.
 */
function auth_consume_token($conn, string $purpose, string $raw): ?array
{
    if (!is_string($raw) || $raw === '' || !auth_table_exists($conn, 'auth_tokens')) {
        return null;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT id, token_hash, purpose, user_role, user_id, expires_at, consumed_at, created_at
             FROM auth_tokens WHERE token_hash = ? AND purpose = ?'
        );
        $stmt->execute([hash('sha256', $raw), $purpose]);
        $row = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $exception) {
        error_log('EduPortal auth token lookup failed: ' . $exception->getMessage());
        return null;
    }

    if (!is_array($row)) {
        return null;
    }

    if (auth_parse_timestamp($row['consumed_at'] ?? null) !== null) {
        return null;
    }

    $expiresAt = auth_parse_timestamp($row['expires_at'] ?? null);
    if ($expiresAt === null || $expiresAt <= time()) {
        return null;
    }

    // Conditional update: if another request consumed it between the read and
    // here, rowCount is 0 and this caller is told the token is spent.
    try {
        $consume = $conn->prepare('UPDATE auth_tokens SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL');
        $consume->execute([auth_now(), (int) $row['id']]);
        if ($consume->rowCount() !== 1) {
            return null;
        }
    } catch (Throwable $exception) {
        error_log('EduPortal auth token consume failed: ' . $exception->getMessage());
        return null;
    }

    return $row;
}

function auth_revoke_tokens($conn, string $purpose, string $role, $userId): int
{
    if (!auth_table_exists($conn, 'auth_tokens')) {
        return 0;
    }

    try {
        $stmt = $conn->prepare(
            'UPDATE auth_tokens SET consumed_at = ?
             WHERE purpose = ? AND user_role = ? AND user_id = ? AND consumed_at IS NULL'
        );
        $stmt->execute([auth_now(), $purpose, $role, (int) $userId]);

        return $stmt->rowCount();
    } catch (Throwable $exception) {
        error_log('EduPortal auth token revoke failed: ' . $exception->getMessage());
        return 0;
    }
}

/**
 * Reads a token without consuming it, so the reset form can be validated on
 * first render instead of after the user has typed a new password. Use
 * auth_consume_token() to actually redeem it.
 */
function auth_token_peek($conn, string $purpose, string $raw): ?array
{
    if (!is_string($raw) || $raw === '' || !auth_table_exists($conn, 'auth_tokens')) {
        return null;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT id, purpose, user_role, user_id, expires_at, consumed_at, created_at
             FROM auth_tokens WHERE token_hash = ? AND purpose = ?'
        );
        $stmt->execute([hash('sha256', $raw), $purpose]);
        $row = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $exception) {
        error_log('EduPortal auth token peek failed: ' . $exception->getMessage());
        return null;
    }

    if (!is_array($row) || auth_parse_timestamp($row['consumed_at'] ?? null) !== null) {
        return null;
    }

    $expiresAt = auth_parse_timestamp($row['expires_at'] ?? null);
    if ($expiresAt === null || $expiresAt <= time()) {
        return null;
    }

    return $row;
}

/**
 * Resolves an account by role.
 *
 * The column list is role-aware because callers feed the result straight into
 * auth_establish_session(), which repopulates the LRN, grade, section and
 * strand for a student. A generic "id, name, email" select here silently
 * produced a student session with an empty LRN and no grade, which broke the
 * dashboard on passkey sign-in.
 */
function auth_account_for_role($conn, string $role, $userId): ?array
{
    $table = auth_role_table($role);
    if ($table === null) {
        return null;
    }

    $columns = $role === 'student'
        ? 'id, lrn, name, email, grade_level, section, strand, password'
        : 'id, name, email, subject, password';

    try {
        $stmt = $conn->prepare("SELECT {$columns} FROM {$table} WHERE id = ?");
        $stmt->execute([(int) $userId]);
        $account = $stmt->get_result()->fetch_assoc();
    } catch (Throwable $exception) {
        error_log('EduPortal auth account lookup failed: ' . $exception->getMessage());
        return null;
    }

    return is_array($account) ? $account : null;
}

/**
 * Rebuilds the same throttling buckets auth_login_buckets() produces, but from
 * a resolved account row rather than from submitted input.
 *
 * This has to match key-for-key or a successful sign-in clears a key that was
 * never written, leaving the real counter in place. Teachers are keyed on
 * "email|subject"; forgetting the subject half silently did nothing.
 */
function auth_account_login_buckets(string $role, array $account): array
{
    $identifier = $role === 'student'
        ? (string) ($account['lrn'] ?? '')
        : (string) ($account['email'] ?? '') . '|' . (string) ($account['subject'] ?? '');

    return auth_login_buckets($role, $identifier);
}

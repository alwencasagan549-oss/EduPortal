<?php
/**
 * Migration: Authentication security tables
 *
 * Creates the three tables the auth layer needs before any stronger credential
 * (passkey / MFA) can be introduced safely:
 *
 *   auth_rate_limits  Persistent per-IP and per-account throttling. The
 *                     previous counters lived in $_SESSION, so discarding the
 *                     session cookie reset the strike count to zero.
 *   auth_events       Security audit trail. Logins, failures, token lifecycle
 *                     and credential changes were previously unrecorded.
 *   auth_tokens       Single-use, hashed tokens for password reset and email
 *                     ownership proof. Only the SHA-256 of a token is stored,
 *                     so a database leak cannot be replayed against a user.
 *
 * Every table is created with IF NOT EXISTS and every index is probed before
 * creation, so re-running is a no-op. The migration exits non-zero if a table
 * could not be created.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/teacher_account.php';

$conn = getDBConnection();
$isMysql = teacher_account_driver_name($conn) === 'mysql';
$schemaExpression = teacher_account_schema_expression($conn);
$pkType = $isMysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'SERIAL PRIMARY KEY';

$failed = false;

/**
 * Returns true when the index is present. MySQL has no CREATE INDEX IF NOT
 * EXISTS, so both dialects need the catalogue probe.
 */
function auth_migration_index_exists($conn, string $schemaExpression, bool $isMysql, string $table, string $index): bool
{
    try {
        $stmt = $conn->prepare(
            $isMysql
                ? "SELECT 1 FROM information_schema.statistics
                   WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?"
                : "SELECT 1 FROM pg_indexes
                   WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?"
        );
        $stmt->execute([$table, $index]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $exception) {
        error_log('EduPortal auth index probe failed: ' . $exception->getMessage());
        return false;
    }
}

/**
 * @param bool $critical When true a failure marks the migration as failed;
 *                       a non-unique token index would be a real defect.
 */
function auth_migration_create_index($conn, string $table, string $index, string $definition, bool $critical = false): bool
{
    $unique = str_starts_with($definition, 'UNIQUE') ? 'UNIQUE ' : '';
    $columns = preg_replace('/^UNIQUE\s+/i', '', $definition);

    try {
        $conn->exec("CREATE {$unique}INDEX {$index} ON {$table} {$columns}");
        echo "  + {$index} created\n";
        return true;
    } catch (Throwable $exception) {
        error_log('EduPortal auth index creation failed: ' . $exception->getMessage());
        echo "  ! {$index} could not be created: " . $exception->getMessage() . "\n";
        return !$critical;
    }
}

$tables = [
    // Persistent throttling. bucket_key is "ip:<addr>" or "<role>:<identifier>"
    // so one row tracks one scope without needing a surrogate key.
    'auth_rate_limits' => "CREATE TABLE IF NOT EXISTS auth_rate_limits (
        bucket_key VARCHAR(190) NOT NULL PRIMARY KEY,
        attempt_count INTEGER NOT NULL DEFAULT 0,
        window_started_at TIMESTAMP NOT NULL,
        locked_until TIMESTAMP NULL,
        updated_at TIMESTAMP NOT NULL
    )",

    // Security audit trail. identifier holds the value the client submitted
    // (LRN or email); it is PII, so this table must never be exposed to
    // non-administrative roles.
    'auth_events' => "CREATE TABLE IF NOT EXISTS auth_events (
        id {$pkType},
        event VARCHAR(48) NOT NULL,
        outcome VARCHAR(24) NOT NULL,
        user_role VARCHAR(20) NULL,
        user_id INTEGER NULL,
        identifier VARCHAR(190) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        detail TEXT NULL,
        created_at TIMESTAMP NOT NULL
    )",

    // Single-use tokens. token_hash is SHA-256 hex; the raw token is emailed
    // and never persisted.
    'auth_tokens' => "CREATE TABLE IF NOT EXISTS auth_tokens (
        id {$pkType},
        token_hash VARCHAR(64) NOT NULL,
        purpose VARCHAR(32) NOT NULL,
        user_role VARCHAR(20) NOT NULL,
        user_id INTEGER NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        consumed_at TIMESTAMP NULL,
        request_ip VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL
    )",
];

foreach ($tables as $name => $sql) {
    try {
        $conn->exec($sql);
        echo "  = {$name} present\n";
    } catch (Throwable $exception) {
        $failed = true;
        echo "  ! {$name} could not be created: " . $exception->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------------
// Indexes. auth_events is append-only and grows without bound, so the read
// paths below are the ones an operator actually runs during an incident.
// ---------------------------------------------------------------------

$indexes = [
    ['auth_events', 'idx_auth_events_user', '(user_role, user_id, created_at DESC)', false],
    ['auth_events', 'idx_auth_events_event', '(event, created_at DESC)', false],
    ['auth_events', 'idx_auth_events_ip', '(ip_address, created_at DESC)', false],
    ['auth_rate_limits', 'idx_auth_rate_limits_locked', '(locked_until)', false],
    // UNIQUE: a token must never resolve to two accounts. A collision would be
    // a 256-bit hash collision, so a duplicate here means tampering, not chance.
    ['auth_tokens', 'idx_auth_tokens_hash', 'UNIQUE (token_hash)', true],
    ['auth_tokens', 'idx_auth_tokens_lookup', '(purpose, user_role, user_id)', false],
];

foreach ($indexes as [$table, $index, $definition, $critical]) {
    if (auth_migration_index_exists($conn, $schemaExpression, $isMysql, $table, $index)) {
        echo "  = {$index} already present\n";
        continue;
    }
    if (!auth_migration_create_index($conn, $table, $index, $definition, $critical)) {
        $failed = true;
    }
}

echo $failed ? "Migration failed.\n" : "Auth security tables migration complete.\n";
exit($failed ? 1 : 0);

<?php
/**
 * Migration: WebAuthn (passkey) credential storage
 *
 * Adds the two tables the passkey ceremonies need, plus a stable opaque
 * user handle per account.
 *
 * Design notes that are easy to undo by accident:
 *
 *   passkeys.user_handle  Opaque random 32 bytes, NOT the row id and NOT the
 *                         LRN. WebAuthn transmits the user handle to the
 *                         relying party in cleartext during registration, so a
 *                         real identifier here would leak student numbers to
 *                         every origin the credential is used against.
 *
 *   passkeys.challenge    Not applicable. The one-time ceremony challenges
 *                         live in webauthn_challenges and are consumed on
 *                         first use.
 *
 *   passkeys.credential_record
 *                         The whole serialized CredentialRecord, not just the
 *                         public key. Verification needs the trust path and
 *                         AAGUID to reconstruct the record, and hand-rolling
 *                         a partial schema is how those get silently dropped.
 *
 * Idempotent: re-running reports the objects as already present and exits 0.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/teacher_account.php';
require_once __DIR__ . '/../libs/AuthService.php';

$conn = getDBConnection();
$isMysql = teacher_account_driver_name($conn) === 'mysql';
$schemaExpression = teacher_account_schema_expression($conn);
$pkType = $isMysql ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'SERIAL PRIMARY KEY';
$boolType = $isMysql ? 'TINYINT(1)' : 'BOOLEAN';
// PostgreSQL rejects "BOOLEAN NOT NULL DEFAULT 0" outright -- it requires a
// boolean literal, not an integer that happens to coerce. MySQL is fine with
// the integer, so the default has to differ by dialect rather than the type.
$boolDefault = $isMysql ? '0' : 'false';

$failed = false;

function passkey_migration_index_exists($conn, string $schemaExpression, bool $isMysql, string $table, string $index): bool
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
        error_log('EduPortal passkey index probe failed: ' . $exception->getMessage());
        return false;
    }
}

function passkey_migration_create_index($conn, string $table, string $index, string $definition, bool $critical = false): bool
{
    $unique = str_starts_with($definition, 'UNIQUE') ? 'UNIQUE ' : '';
    $columns = preg_replace('/^UNIQUE\s+/i', '', $definition);

    try {
        $conn->exec("CREATE {$unique}INDEX {$index} ON {$table} {$columns}");
        echo "  + {$index} created\n";
        return true;
    } catch (Throwable $exception) {
        error_log('EduPortal passkey index creation failed: ' . $exception->getMessage());
        echo "  ! {$index} could not be created: " . $exception->getMessage() . "\n";
        return !$critical;
    }
}

$tables = [
    // credential_id is TEXT rather than VARCHAR: the spec allows credential
    // IDs up to 1023 bytes, which base64url-encodes past any sane VARCHAR.
    'passkeys' => "CREATE TABLE IF NOT EXISTS passkeys (
        id {$pkType},
        user_role VARCHAR(20) NOT NULL,
        user_id INTEGER NOT NULL,
        user_handle VARCHAR(64) NOT NULL,
        credential_id TEXT NOT NULL,
        credential_record TEXT NOT NULL,
        aaguid VARCHAR(36) NULL,
        transports VARCHAR(190) NULL,
        sign_count INTEGER NOT NULL DEFAULT 0,
        backup_eligible {$boolType} NOT NULL DEFAULT {$boolDefault},
        backup_status {$boolType} NOT NULL DEFAULT {$boolDefault},
        label VARCHAR(64) NOT NULL DEFAULT 'Passkey',
        created_at TIMESTAMP NOT NULL,
        last_used_at TIMESTAMP NULL,
        revoked_at TIMESTAMP NULL
    )",

    'webauthn_challenges' => "CREATE TABLE IF NOT EXISTS webauthn_challenges (
        id {$pkType},
        challenge VARCHAR(128) NOT NULL,
        purpose VARCHAR(16) NOT NULL,
        user_role VARCHAR(20) NULL,
        user_id INTEGER NULL,
        request_ip VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        consumed_at TIMESTAMP NULL
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

// A stable opaque handle, allocated on first passkey enrolment and reused for
// every later credential on the same account.
foreach (['students', 'teachers'] as $table) {
    if (auth_column_exists($conn, $table, 'passkey_user_handle')) {
        echo "  = {$table}.passkey_user_handle already present\n";
        continue;
    }

    try {
        $conn->exec("ALTER TABLE {$table} ADD COLUMN passkey_user_handle VARCHAR(64) NULL");
        echo "  + {$table}.passkey_user_handle added\n";
    } catch (Throwable $exception) {
        $failed = true;
        echo "  ! {$table}.passkey_user_handle could not be created: " . $exception->getMessage() . "\n";
    }
}

$indexes = [
    // UNIQUE: one credential id may belong to exactly one account. Without
    // this, a replayed registration could attach a credential to a second user.
    ['passkeys', 'idx_passkeys_credential', 'UNIQUE (credential_id)', true],
    ['passkeys', 'idx_passkeys_account', '(user_role, user_id, revoked_at)', false],
    ['passkeys', 'idx_passkeys_handle', '(user_handle)', false],
    ['webauthn_challenges', 'idx_webauthn_challenges_challenge', 'UNIQUE (challenge)', true],
    ['webauthn_challenges', 'idx_webauthn_challenges_expiry', '(expires_at)', false],
];

foreach ($indexes as [$table, $index, $definition, $critical]) {
    if (passkey_migration_index_exists($conn, $schemaExpression, $isMysql, $table, $index)) {
        echo "  = {$index} already present\n";
        continue;
    }
    if (!passkey_migration_create_index($conn, $table, $index, $definition, $critical)) {
        $failed = true;
    }
}

echo $failed ? "Migration failed.\n" : "Passkey tables migration complete.\n";
exit($failed ? 1 : 0);

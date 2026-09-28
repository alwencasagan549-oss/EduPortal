<?php
/**
 * Migration: uniqueness constraints on accounts
 *
 * The application checks uniqueness before inserting, but a check-then-insert
 * is not atomic: two concurrent signups can both pass the check and both
 * insert. The database is the only place that can actually guarantee it.
 *
 * Uniqueness is deliberately per-pair rather than per-column, because the
 * schema allows what the school needs:
 *
 *   teachers  One person may teach several subjects, which is several rows
 *             sharing an email. Two people may also share a subject. Only
 *             the same email with the same subject is a duplicate.
 *
 *   students  An LRN and an email each identify exactly one student, so both
 *             are constrained independently.
 *
 * Both indexes are case- and whitespace-insensitive, matching the
 * normalisation the application performs. A raw index on (email, subject)
 * would let "Math" and "math" both be registered for the same person, which
 * is the same account under two spellings.
 *
 * student emails are nullable, and a unique index treats NULLs as distinct in
 * PostgreSQL, so students with no address on file are still allowed.
 *
 * Idempotent: re-running reports the indexes as already present and exits 0.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/AuthService.php';
require_once __DIR__ . '/../libs/teacher_account.php';

$conn = getDBConnection();
$isMysql = teacher_account_driver_name($conn) === 'mysql';
$schemaExpression = teacher_account_schema_expression($conn);

$failed = false;

/**
 * A unique index is refused while duplicates exist, so both are reported and
 * the index is skipped rather than aborting the whole migration. Duplicates
 * are a data problem the operator resolves, not a schema problem.
 */
function account_unique_index_exists($conn, string $schemaExpression, bool $isMysql, string $index): bool
{
    try {
        $stmt = $conn->prepare(
            $isMysql
                ? "SELECT 1 FROM information_schema.statistics
                   WHERE table_schema = DATABASE() AND index_name = ?"
                : "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?"
        );
        $stmt->execute([$index]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $exception) {
        error_log('EduPortal account index probe failed: ' . $exception->getMessage());
        return false;
    }
}

function account_migration_duplicates($conn, string $table): int
{
    $normalised = $table === 'teachers'
        ? 'LOWER(TRIM(email)), LOWER(TRIM(subject))'
        : 'LOWER(TRIM(email))';

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM (SELECT {$normalised} FROM {$table} GROUP BY 1 HAVING COUNT(*) > 1) AS dupes");
        $stmt->execute();
        return (int) ($stmt->get_result()->fetchColumn() ?: 0);
    } catch (Throwable $exception) {
        error_log('EduPortal account duplicate scan failed: ' . $exception->getMessage());
        return 0;
    }
}

$indexes = [
    [
        'name' => 'idx_teachers_email_subject_unique',
        'table' => 'teachers',
        // MySQL has no expression indexes before 8.0.13 and they are limited
        // even after it, so that dialect gets a plain column index and relies
        // on the application check for case-insensitivity.
        'definition' => $isMysql
            ? 'UNIQUE (email, subject)'
            : 'UNIQUE (LOWER(TRIM(email)), LOWER(TRIM(subject)))',
    ],
    [
        'name' => 'idx_students_email_unique',
        'table' => 'students',
        'definition' => $isMysql
            ? 'UNIQUE (email)'
            : 'UNIQUE (LOWER(TRIM(email)))',
    ],
];

foreach ($indexes as $index) {
    $name = $index['name'];

    if (account_unique_index_exists($conn, $schemaExpression, $isMysql, $name)) {
        echo "  = {$name} already present\n";
        continue;
    }

    $duplicates = account_migration_duplicates($conn, $index['table']);
    if ($duplicates > 0) {
        echo "  ! {$index['table']} has {$duplicates} duplicate group(s); resolve them before adding {$name}\n";
        $failed = true;
        continue;
    }

    try {
        $conn->exec("CREATE UNIQUE INDEX {$name} ON {$index['table']} ({$index['definition']})");
        echo "  + {$name} created\n";
    } catch (Throwable $exception) {
        error_log('EduPortal account index creation failed: ' . $exception->getMessage());
        echo "  ! {$name} could not be created: " . $exception->getMessage() . "\n";
        $failed = true;
    }
}

echo $failed ? "Migration incomplete. No indexes were added where duplicates exist.\n" : "Account uniqueness migration complete.\n";
exit($failed ? 1 : 0);

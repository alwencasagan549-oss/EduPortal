<?php
/**
 * Migration: Submission storage columns and supporting indexes
 *
 * These ALTER TABLE statements used to run inside controllers on every
 * submission and every download. Runtime DDL costs an information_schema
 * round-trip on a hot path, requires privileges production should not grant,
 * and holds an ACCESS EXCLUSIVE lock that blocks concurrent submissions.
 *
 * Run once at deploy time:
 *   php migrations/add_submission_storage_columns.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/assignment_management.php';

$conn = getDBConnection();
$isMysql = assignment_driver_name($conn) === 'mysql';
$failed = false;

function add_column(PDO $pdo, string $sql, string $label): bool
{
    try {
        $pdo->exec($sql);
        echo "  + {$label}\n";
        return true;
    } catch (Throwable $exception) {
        // Duplicate column / already-exists is a success, not a failure.
        if (str_contains($exception->getMessage(), 'exists')
            || (isset($exception->errorInfo[1]) && in_array((int) $exception->errorInfo[1], [1060, 1061, 42701], true))) {
            echo "  = {$label} already present\n";
            return true;
        }
        echo "  ! {$label} failed: " . $exception->getMessage() . "\n";
        return false;
    }
}

$pdo = $conn->getPDO();

echo "Applying submissions storage columns...\n";

$failed = add_column(
    $pdo,
    $isMysql
        ? 'ALTER TABLE submissions ADD COLUMN file_content LONGBEXT'
        : 'ALTER TABLE submissions ADD COLUMN file_content TEXT DEFAULT NULL',
    'submissions.file_content'
) || $failed;

$failed = add_column(
    $pdo,
    "ALTER TABLE submissions ADD COLUMN file_type VARCHAR(100) DEFAULT 'application/octet-stream'",
    'submissions.file_type'
) || $failed;

$failed = add_column(
    $pdo,
    'ALTER TABLE submissions ADD COLUMN assignment_id INTEGER DEFAULT NULL',
    'submissions.assignment_id'
) || $failed;

$failed = add_column(
    $pdo,
    'ALTER TABLE submissions ADD COLUMN teacher_id INTEGER DEFAULT NULL',
    'submissions.teacher_id'
) || $failed;

// posted_assignments.file_content backs the Render compatibility path.
$postedColumns = $conn->prepare(
    $isMysql
        ? "SELECT column_name FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'posted_assignments'"
        : "SELECT column_name FROM information_schema.columns
           WHERE table_schema = current_schema() AND table_name = 'posted_assignments'"
);
$postedColumns->execute();
$postedColumnNames = [];
while (($row = $postedColumns->fetch_assoc()) !== false) {
    $postedColumnNames[strtolower((string) $row['column_name'])] = true;
}

if (!isset($postedColumnNames['file_content'])) {
    $failed = add_column(
        $pdo,
        $isMysql
            ? 'ALTER TABLE posted_assignments ADD COLUMN file_content LONGTEXT'
            : 'ALTER TABLE posted_assignments ADD COLUMN file_content TEXT DEFAULT NULL',
        'posted_assignments.file_content'
    ) || $failed;
}
if (!isset($postedColumnNames['file_type'])) {
    $failed = add_column(
        $pdo,
        "ALTER TABLE posted_assignments ADD COLUMN file_type VARCHAR(100) DEFAULT 'application/octet-stream'",
        'posted_assignments.file_type'
    ) || $failed;
}

echo "Applying submissions indexes...\n";

$indexes = [
    'idx_submissions_student_submitted' =>
        'CREATE INDEX IF NOT EXISTS idx_submissions_student_submitted ON submissions (student_id, submitted_at DESC)',
    'idx_submissions_teacher_submitted' =>
        'CREATE INDEX IF NOT EXISTS idx_submissions_teacher_submitted ON submissions (teacher_id, submitted_at DESC)',
    'idx_submissions_legacy_subject' =>
        'CREATE INDEX IF NOT EXISTS idx_submissions_legacy_subject ON submissions (subject) WHERE teacher_id IS NULL',
    'idx_submissions_student_subject_normalized' =>
        'CREATE INDEX IF NOT EXISTS idx_submissions_student_subject_normalized ON submissions (student_id, LOWER(TRIM(subject))) WHERE assignment_id IS NULL',
];

foreach ($indexes as $name => $sql) {
    $failed = add_column($pdo, $sql, $name) || $failed;
}

if (!assignment_submission_unique_index_exists($conn)) {
    $indexSql = 'CREATE UNIQUE INDEX IF NOT EXISTS idx_submissions_student_assignment_unique
                 ON submissions (student_id, assignment_id)';
    if (!$isMysql) {
        $indexSql .= ' WHERE assignment_id IS NOT NULL';
    }
    $failed = add_column($pdo, $indexSql, 'idx_submissions_student_assignment_unique') || $failed;
} else {
    echo "  = idx_submissions_student_assignment_unique already present\n";
}

assignment_reset_submission_feature_cache();

echo $failed ? "\nMigration incomplete. See the errors above.\n" : "\nMigration complete.\n";
exit($failed ? 1 : 0);

<?php
/**
 * Migration: Teacher account approval gate
 *
 * Self-service teacher registration previously granted an immediately usable
 * teacher account. Because teacher visibility is scoped by `teachers.subject`,
 * anyone could claim an unused subject and read every submission filed under
 * it. This adds a status column; accounts stay unusable until approved.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/teacher_account.php';

$conn = getDBConnection();
$isMysql = teacher_account_driver_name($conn) === 'mysql';

$failed = false;

try {
    if (!teacher_account_column_exists($conn, 'status')) {
        $default = "'pending'";
        $conn->exec("ALTER TABLE teachers ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT {$default}");
        echo "  + teachers.status added (default pending)\n";
    } else {
        echo "  = teachers.status already present\n";
    }
} catch (Throwable $exception) {
    $failed = true;
    echo "  ! teachers.status could not be created: " . $exception->getMessage() . "\n";
}

// Existing accounts were provisioned before the gate existed; keep them working.
try {
    $conn->exec("UPDATE teachers SET status = 'approved' WHERE status IS NULL OR status = ''");
    echo "  = backfilled blank statuses to approved\n";
} catch (Throwable $exception) {
    $failed = true;
    echo "  ! status backfill failed: " . $exception->getMessage() . "\n";
}

try {
    $exists = $conn->prepare(
        $isMysql
            ? "SELECT 1 FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'teachers' AND index_name = ?"
            : "SELECT 1 FROM pg_indexes
               WHERE schemaname = current_schema() AND tablename = 'teachers' AND indexname = ?"
    );
    $exists->execute(['idx_teachers_status']);
    if ($exists->fetchColumn() === false) {
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_teachers_status ON teachers (status)');
        echo "  + idx_teachers_status created\n";
    }
} catch (Throwable $exception) {
    echo "  ! idx_teachers_status skipped: " . $exception->getMessage() . "\n";
}

echo $failed ? "Migration failed.\n" : "Teacher approval migration complete.\n";
exit($failed ? 1 : 0);

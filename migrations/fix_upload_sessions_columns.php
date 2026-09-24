<?php
/**
 * Migration: Remove upload value length limits
 * Fixes SQLSTATE[22001]: String data, right truncated errors
 * for long filenames, storage identifiers, and generated paths
 */

require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

echo "Checking upload column schema...\n";

$targets = [
    ['table' => 'upload_sessions', 'column' => 'original_filename'],
    ['table' => 'upload_sessions', 'column' => 'object_key'],
    ['table' => 'upload_sessions', 'column' => 'stored_filename'],
    ['table' => 'upload_sessions', 'column' => 's3_upload_id'],
    ['table' => 'posted_assignments', 'column' => 'file_path'],
    ['table' => 'submissions', 'column' => 'file_path'],
];

$stmt = $conn->query("
    SELECT table_name, column_name, data_type, character_maximum_length
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND (
          (table_name = 'upload_sessions' AND column_name IN ('original_filename', 'object_key', 'stored_filename', 's3_upload_id'))
          OR (table_name IN ('posted_assignments', 'submissions') AND column_name = 'file_path')
      )
");
$columns = $stmt->fetch_all();
$existing = [];

foreach ($columns as $column) {
    $key = $column['table_name'] . '.' . $column['column_name'];
    $length = $column['character_maximum_length'] ?? 'unlimited';
    echo sprintf("  - %s: %s (%s)\n", $key, $column['data_type'], $length);
    $existing[$key] = true;
}

echo "\nApplying column modifications...\n";
$failed = false;

foreach ($targets as $target) {
    $table = $target['table'];
    $column = $target['column'];
    $key = $table . '.' . $column;

    if (!isset($existing[$key])) {
        echo "  - $key: column not found; skipped\n";
        continue;
    }

    try {
        $conn->exec("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE TEXT");
        echo "  ✓ $key set to TEXT\n";
    } catch (Throwable $e) {
        $failed = true;
        echo "  - $key: " . $e->getMessage() . "\n";
    }
}

echo "\nMigration completed.\n";
exit($failed ? 1 : 0);
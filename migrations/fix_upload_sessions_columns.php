<?php
/**
 * Migration: Fix upload_sessions column sizes
 * Fixes SQLSTATE[22001]: String data, right truncated error
 * for long filenames exceeding VARCHAR(255) limit
 */

require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

echo "Checking upload_sessions table schema...\n";

$stmt = $conn->prepare("
    SELECT column_name, data_type, character_maximum_length
    FROM information_schema.columns 
    WHERE table_name = 'upload_sessions' 
    AND column_name IN ('original_filename', 'object_key', 'stored_filename')
");
$stmt->execute();
$columns = $stmt->fetchAll();

foreach ($columns as $col) {
    $len = $col['character_maximum_length'] ?? 'unlimited';
    echo sprintf("  - %s: %s (%s)\n", $col['column_name'], $col['data_type'], $len);
}

echo "\nApplying column modifications...\n";

// Fix original_filename
$affected1 = $conn->exec("ALTER TABLE upload_sessions ALTER COLUMN original_filename TYPE TEXT");
if ($affected1 !== false) {
    echo "  ✓ original_filename set to TEXT\n";
} else {
    $error = $conn->getPDO()->errorInfo();
    echo "  - original_filename: " . $error[2] . "\n";
}

// Fix object_key
$affected2 = $conn->exec("ALTER TABLE upload_sessions ALTER COLUMN object_key TYPE TEXT");
if ($affected2 !== false) {
    echo "  ✓ object_key set to TEXT\n";
} else {
    $error = $conn->getPDO()->errorInfo();
    echo "  - object_key: " . $error[2] . "\n";
}

// Fix stored_filename
$affected3 = $conn->exec("ALTER TABLE upload_sessions ALTER COLUMN stored_filename TYPE VARCHAR(500)");
if ($affected3 !== false) {
    echo "  ✓ stored_filename set to VARCHAR(500)\n";
} else {
    $error = $conn->getPDO()->errorInfo();
    echo "  - stored_filename: " . $error[2] . "\n";
}

echo "\nMigration completed.\n";
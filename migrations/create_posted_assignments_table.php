<?php
/**
 * Database Migration: Posted Assignments Table
 */
require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

$sql = "CREATE TABLE IF NOT EXISTS posted_assignments (
    id SERIAL PRIMARY KEY,
    teacher_id INTEGER NOT NULL,
    teacher_name VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path TEXT NOT NULL,
    file_content TEXT,
    file_type VARCHAR(100) DEFAULT 'application/octet-stream',
    grade_level VARCHAR(50) NOT NULL,
    strand VARCHAR(50) NOT NULL,
    section VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

$storageSql = "ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_content TEXT, ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream'";
$indexSql = "CREATE INDEX IF NOT EXISTS idx_posted_assignments_teacher_created ON posted_assignments (teacher_id, created_at DESC)";
$targetIndexSql = "CREATE INDEX IF NOT EXISTS idx_posted_assignments_target_created ON posted_assignments (grade_level, strand, section, created_at DESC)";

$tableReady = $conn->query($sql) !== false;
$storageReady = $tableReady && $conn->query($storageSql) !== false;
$indexReady = $storageReady && $conn->query($indexSql) !== false;
$targetIndexReady = $indexReady && $conn->query($targetIndexSql) !== false;

if ($targetIndexReady) {
    echo "Table 'posted_assignments' and teacher lookup index are ready.\n";
} else {
    echo "Error preparing posted_assignments: " . $conn->getPDO()->errorInfo()[2] . "\n";
}

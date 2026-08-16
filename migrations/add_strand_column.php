<?php
// Migration: Add strand column to students and posted_assignments tables
require_once 'config/database.php';
$conn = getDBConnection();

$sql1 = "ALTER TABLE students ADD COLUMN IF NOT EXISTS strand VARCHAR(50) DEFAULT 'Academic'";
$sql2 = "ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS strand VARCHAR(50) DEFAULT 'Academic'";

if ($conn->exec($sql1)) {
    echo "students.strand column added/verified.\n";
} else {
    echo "Error adding strand to students: " . $conn->getPDO()->errorInfo()[2] . "\n";
}

if ($conn->exec($sql2)) {
    echo "posted_assignments.strand column added/verified.\n";
} else {
    echo "Error adding strand to posted_assignments: " . $conn->getPDO()->errorInfo()[2] . "\n";
}

echo "Migration complete.\n";

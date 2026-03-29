<?php
/**
 * Migration: Row Level Security Hardening
 * Adds 'teacher_id' to submissions to ensure explicit ownership and accountability.
 */

require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

// Check if teacher_id column exists
$check_column = $conn->query("SHOW COLUMNS FROM submissions LIKE 'teacher_id'");
if ($check_column->num_rows === 0) {
    echo "Adding 'teacher_id' to submissions table...\n";
    $sql = "ALTER TABLE submissions ADD COLUMN teacher_id INT(11) DEFAULT NULL AFTER student_id";
    if ($conn->query($sql)) {
        echo "Success: Column added.\n";
    } else {
        echo "Error: " . $conn->error . "\n";
    }
} else {
    echo "Column 'teacher_id' already exists.\n";
}

$conn->close();
echo "Migration Complete.\n";

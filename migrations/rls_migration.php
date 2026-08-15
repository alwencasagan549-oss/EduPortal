<?php
// rls_migration.php - Row Level Security migration
require_once 'config/database.php';

$conn = getDBConnection();

$check_column = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'submissions' AND column_name = 'teacher_id'");

if ($check_column->num_rows() === 0) {
    echo "Adding 'teacher_id' to submissions table...\n";
    $sql = "ALTER TABLE submissions ADD COLUMN teacher_id INTEGER DEFAULT NULL";
    if ($conn->query($sql)) {
        echo "Success: Column added.\n";
    } else {
        echo "Error: " . $conn->getPDO()->errorInfo()[2] . "\n";
    }
} else {
    echo "Column 'teacher_id' already exists.\n";
}

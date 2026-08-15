<?php
/**
 * Database Migration: Posted Assignments Table
 */
require_once 'config/database.php';

$conn = getDBConnection();

$sql = "CREATE TABLE IF NOT EXISTS posted_assignments (
    id SERIAL PRIMARY KEY,
    teacher_id INTEGER NOT NULL,
    teacher_name VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path VARCHAR(255) NOT NULL,
    grade_level VARCHAR(50) NOT NULL,
    section VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql)) {
    echo "Table 'posted_assignments' created successfully.\n";
} else {
    echo "Error creating table: " . $conn->getPDO()->errorInfo()[2];
}

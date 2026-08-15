<?php
/**
 * Database Migration: Job Queue Table
 */
require_once 'config/database.php';

$conn = getDBConnection();

$sql = "CREATE TABLE IF NOT EXISTS jobs (
    id SERIAL PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    payload TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql)) {
    echo "Job queue table created successfully.";
} else {
    echo "Error creating table: " . $conn->getPDO()->errorInfo()[2];
}

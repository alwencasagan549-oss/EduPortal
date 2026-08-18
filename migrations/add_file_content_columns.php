<?php
/**
 * Database Migration: Add file_content columns to assignments and submissions
 * Stores file data as base64 in DB so downloads work on Render (ephemeral filesystem)
 */
require_once 'config/database.php';
$conn = getDBConnection();

// Add columns to posted_assignments
$conn->query("ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_content MEDIUMTEXT DEFAULT NULL");
$conn->query("ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream'");

// Add columns to submissions
$conn->query("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_content MEDIUMTEXT DEFAULT NULL");
$conn->query("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream'");

echo "Migration completed: file_content columns added.\n";

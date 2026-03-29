<?php
/**
 * Migration Script: Create Job Queue Table
 */

require_once 'config/database.php';

$conn = getDBConnection();

$sql = "CREATE TABLE IF NOT EXISTS jobs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type VARCHAR(50) NOT NULL,
    payload TEXT NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";

if ($conn->query($sql) === TRUE) {
    echo "Job queue table created successfully.";
} else {
    echo "Error creating table: " . $conn->error;
}

$conn->close();
?>

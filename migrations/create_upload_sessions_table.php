<?php
/**
 * Database Migration: Upload Sessions Table
 * Supports multipart/chunked upload tracking for resumable uploads.
 */
require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

$sql = "CREATE TABLE IF NOT EXISTS upload_sessions (
    id SERIAL PRIMARY KEY,
    upload_id VARCHAR(64) NOT NULL UNIQUE,
    user_id INTEGER NOT NULL,
    user_role VARCHAR(20) NOT NULL,
    original_filename TEXT NOT NULL,
    stored_filename TEXT,
    file_size BIGINT NOT NULL,
    mime_type VARCHAR(100),
    chunk_size INTEGER NOT NULL,
    total_chunks INTEGER NOT NULL,
    completed_chunks TEXT DEFAULT '[]',
    presigned_urls TEXT DEFAULT '[]',
    object_key TEXT,
    s3_upload_id TEXT,
    status VARCHAR(20) DEFAULT 'initiated',
    error_message TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->exec($sql) !== false) {
    echo "Table 'upload_sessions' created or already exists.\n";
} else {
    echo "Error creating table: " . $conn->getPDO()->errorInfo()[2] . "\n";
}

// completed_chunks and presigned_urls are stored as JSON text rather than an
// INTEGER[] / JSONB: PDO returns a Postgres array as the string "{1,2,3}",
// which broke array operations in the resume endpoint, and TEXT behaves
// identically on both supported drivers.
$conversions = [
    ['completed_chunks', 'TEXT DEFAULT \'[]\''],
    ['presigned_urls', 'TEXT DEFAULT \'[]\''],
];
foreach ($conversions as [$column, $type]) {
    try {
        $conn->exec("ALTER TABLE upload_sessions ALTER COLUMN {$column} TYPE {$type}");
        echo "Column {$column} normalised to TEXT.\n";
    } catch (Throwable $exception) {
        echo "Column {$column} left as-is: " . $exception->getMessage() . "\n";
    }
}

$indexSql = "CREATE INDEX IF NOT EXISTS idx_upload_sessions_upload_id ON upload_sessions (upload_id)";
$conn->exec($indexSql);

$indexSql2 = "CREATE INDEX IF NOT EXISTS idx_upload_sessions_user ON upload_sessions (user_id, user_role)";
$conn->exec($indexSql2);

$indexSql3 = "CREATE INDEX IF NOT EXISTS idx_upload_sessions_status ON upload_sessions (status)";
$conn->exec($indexSql3);

echo "Indexes ensured.\n";

<?php
/**
 * Secure Downloader with Row Level Security (RLS)
 * Bound to the authenticated Session ID.
 * Falls back to database-stored content for Render compatibility.
 */

// Load Secure Session & Database
require_once __DIR__ . '/../config/database.php';

// session_start handled by database.php

// RLS: Only allow authenticated users
if (!isLoggedIn()) {
    header('Location: ../session_expired.php');
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid request');
}

$id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

$conn = getDBConnection();

// Auto-create file_content columns if they don't exist (self-healing)
try {
    $conn->query("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_content TEXT DEFAULT NULL");
    $conn->query("ALTER TABLE submissions ADD COLUMN IF NOT EXISTS file_type VARCHAR(100) DEFAULT 'application/octet-stream'");
} catch (Exception $e) {
    // Columns may already exist
}

// Different queries based on user role
if ($user_role === 'teacher') {
    if (isset($_SESSION['user_subject'])) {
        $teacher_subject = $_SESSION['user_subject'];
        $stmt = $conn->prepare("SELECT file_path, file_content, file_type FROM submissions WHERE id = ? AND subject = ?");
        $stmt->execute([$id, $teacher_subject]);
    } else {
        $stmt = $conn->prepare("SELECT file_path, file_content, file_type FROM submissions WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$id, $user_id]);
    }
} else {
    $stmt = $conn->prepare("SELECT file_path, file_content, file_type FROM submissions WHERE id = ? AND student_id = ?");
    $stmt->execute([$id, $user_id]);
}

$result = $stmt->get_result();

if ($result->num_rows() === 0) {
    die('File not found or you do not have permission to access this file');
}

$submission = $result->fetch_assoc();
$file_path = $submission['file_path'];

// Serve from database if content is stored there (Render compatibility)
if (!empty($submission['file_content'])) {
    $file_data = base64_decode($submission['file_content']);
    $filename = basename($file_path);
    $filetype = $submission['file_type'] ?: 'application/octet-stream';
    $filesize = strlen($file_data);

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $filetype);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . $filesize);
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    if (ob_get_level()) {
        ob_end_clean();
    }

    echo $file_data;
    exit();
}

// Fallback: serve from filesystem
$base_dir = realpath(__DIR__ . '/../uploads');

if ($base_dir === false) {
    die('Server configuration error: uploads directory not found');
}

$relative = ltrim($file_path, '/\\');
$resolved = $base_dir . DIRECTORY_SEPARATOR . $relative;

$base_norm = rtrim(str_replace('\\', '/', $base_dir), '/') . '/';
$resolved_norm = str_replace('\\', '/', $resolved);

if (strpos($resolved_norm, $base_norm) !== 0) {
    die('Access denied: invalid file path');
}

if (!file_exists($resolved)) {
    die('File not found on server');
}

$filename = basename($resolved);
$filetype = mime_content_type($resolved);
$filesize = filesize($resolved);

header('Content-Description: File Transfer');
header('Content-Type: ' . $filetype);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $filesize);
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Expires: 0');

if (ob_get_level()) {
    ob_end_clean();
}

readfile($resolved);

exit();
?>

<?php
/**
 * Secure Downloader for Teacher-Broadcasted Assignments
 * Looks up file from posted_assignments table and serves with RLS.
 */

require_once __DIR__ . '/../config/database.php';

if (!isLoggedIn()) {
    header('Location: ../session_expired.php');
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid request');
}

$id = intval($_GET['id']);
$user_role = $_SESSION['user_role'] ?? '';
$conn = getDBConnection();

// Auto-create file_content columns if they don't exist (self-healing, PostgreSQL + MySQL safe)
try {
    $check = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'posted_assignments' AND column_name = 'file_content'");
    $check->execute();
    $exists = $check->fetchColumn();
    if (!$exists) {
        $conn->exec("ALTER TABLE posted_assignments ADD COLUMN file_content TEXT DEFAULT NULL");
        $conn->exec("ALTER TABLE posted_assignments ADD COLUMN file_type VARCHAR(100) DEFAULT 'application/octet-stream'");
    }
} catch (Throwable $e) {
    // Columns may already exist
}

$stmt = $conn->prepare("SELECT file_path, file_content, file_type FROM posted_assignments WHERE id = ?");
$stmt->execute([$id]);
$result = $stmt->get_result();

if ($result->num_rows() === 0) {
    die('Assignment not found');
}

$assignment = $result->fetch_assoc();
$file_path = $assignment['file_path'];

// Serve from database if content is stored there (Render compatibility)
if (!empty($assignment['file_content'])) {
    $file_data = base64_decode($assignment['file_content']);
    $filename = basename($file_path);
    $filetype = $assignment['file_type'] ?: 'application/octet-stream';
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
    die('File not available. Please contact your teacher to re-upload the assignment.');
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

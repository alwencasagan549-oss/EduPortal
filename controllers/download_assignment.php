<?php
/**
 * Secure Downloader for Teacher-Broadcasted Assignments
 * Looks up file from posted_assignments table and serves with RLS.
 */

require_once __DIR__ . '/../config/database.php';

if (!isLoggedIn() && !isAdminLoggedIn()) {
    header('Location: ../session_expired.php');
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid request');
}

$id = intval($_GET['id']);
$user_role = $_SESSION['user_role'] ?? '';
$conn = getDBConnection();

$stmt = $conn->prepare("SELECT file_path, title, grade_level, strand, section FROM posted_assignments WHERE id = ?");
$stmt->execute([$id]);
$result = $stmt->get_result();

if ($result->num_rows() === 0) {
    die('Assignment not found');
}

$assignment = $result->fetch_assoc();
$file_path = $assignment['file_path'];

$base_dir = realpath(__DIR__ . '/../uploads');
$resolved = realpath(__DIR__ . '/../' . ltrim($file_path, '/\\'));

if ($resolved === false || strpos($resolved, $base_dir) !== 0) {
    die('Access denied: invalid file path');
}

if (!file_exists($resolved)) {
    die('File not found on server');
}

if (!file_exists($real_path)) {
    die('File not found on server');
}

$filename = basename($real_path);
$filetype = mime_content_type($real_path);
$filesize = filesize($real_path);

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

readfile($real_path);
exit();

<?php
/**
 * Secure Downloader with Row Level Security (RLS)
 * Bound to the authenticated Session ID.
 */

// Load Secure Session & Database
require_once __DIR__ . '/../config/database.php';

// session_start handled by database.php

// RLS: Only allow authenticated users
if (!isLoggedIn() && !isAdminLoggedIn()) {
    header('Location: ../index.php');
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid request');
}

$id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

$conn = getDBConnection();

// Different queries based on user role
if ($user_role === 'teacher') {
    // Teacher can download any submission for their subject
    if (isset($_SESSION['user_subject'])) {
        $teacher_subject = $_SESSION['user_subject'];
        $stmt = $conn->prepare("SELECT file_path FROM submissions WHERE id = ? AND subject = ?");
        $stmt->bind_param("is", $id, $teacher_subject);
    } else {
        // If no subject in session, allow download based on teacher_id
        $stmt = $conn->prepare("SELECT file_path FROM submissions WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("ii", $id, $user_id);
    }
} else {
    // Student can only download their own submissions
    $stmt = $conn->prepare("SELECT file_path FROM submissions WHERE id = ? AND student_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('File not found or you do not have permission to access this file');
}

$submission = $result->fetch_assoc();
$file_path = $submission['file_path'];

if (!file_exists($file_path)) {
    die('File not found on server: ' . htmlspecialchars($file_path));
}

// Get file info
$filename = basename($file_path);
$filetype = mime_content_type($file_path);
$filesize = filesize($file_path);

// Set headers for download
header('Content-Description: File Transfer');
header('Content-Type: ' . $filetype);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $filesize);
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Expires: 0');

// Clear output buffer
if (ob_get_level()) {
    ob_end_clean();
}

readfile($file_path);

$stmt->close();
$conn->close();
exit();
?>
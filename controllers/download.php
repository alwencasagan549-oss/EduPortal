<?php
/**
 * Secure Downloader for student submissions.
 *
 * Visibility rules:
 *   - a student may only download their own submission
 *   - a teacher may download submissions assigned to them, plus legacy rows
 *     (teacher_id IS NULL) that match their subject AND one of the classes
 *     they actually teach
 *
 * Served from the database when content is stored there, otherwise from the
 * uploads directory behind a realpath()-based traversal check.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/assignment_management.php';

// session_start handled by database.php

if (!isLoggedIn()) {
    header('Location: ../session_expired.php');
    exit();
}

$id = assignment_id($_GET['id'] ?? null);
if ($id === null) {
    http_response_code(400);
    die('Invalid request');
}

$user_id = (int) $_SESSION['user_id'];
$user_role = (string) ($_SESSION['user_role'] ?? '');
$conn = getDBConnection();

// Report readiness rather than repairing the schema inline: a download is a
// hot path and runtime DDL there cost an information_schema round-trip per
// file. Run migrations/add_submission_storage_columns.php at deploy time.
if (!assignment_submission_has_column($conn, 'file_type')) {
    http_response_code(503);
    die('Submission storage is not ready. Please contact the administrator.');
}

$select = 'SELECT file_path, file_content, file_type FROM submissions WHERE id = ?';

if ($user_role === 'teacher') {
    $teacherSubject = trim((string) ($_SESSION['user_subject'] ?? ''));
    $scope = assignment_teacher_submission_scope('s', $user_id, $teacherSubject);
    $stmt = $conn->prepare(
        "SELECT s.file_path, s.file_content, s.file_type
         FROM submissions s
         WHERE s.id = ? AND " . $scope['sql']
    );
    $stmt->execute(array_merge([$id], $scope['params']));
} else {
    $stmt = $conn->prepare($select . ' AND student_id = ?');
    $stmt->execute([$id, $user_id]);
}

$submission = $stmt->get_result()->fetch_assoc();

if (!$submission) {
    http_response_code(404);
    die('File not found or you do not have permission to access this file');
}

$file_path = (string) $submission['file_path'];

$filename = assignment_safe_download_filename($file_path);
$filetype = assignment_safe_download_mime($submission['file_type'] ?? '');

if (!empty($submission['file_content'])) {
    $file_data = base64_decode((string) $submission['file_content'], true);
    if ($file_data === false) {
        http_response_code(500);
        die('The stored copy of this file could not be decoded.');
    }

    assignment_stream_download($file_data, $filename, $filetype);
}

$base_dir = realpath(__DIR__ . '/../uploads');
if ($base_dir === false) {
    http_response_code(500);
    die('Server configuration error: uploads directory not found');
}

$relative = ltrim(str_replace('\\', '/', $file_path), '/');
if (strpos($relative, 'uploads/') === 0) {
    $relative = substr($relative, strlen('uploads/'));
}

// realpath() first, then compare. The previous check compared a *string*
// prefix on a non-canonical path, so a stored path containing "../" produced
// a string that still started with the uploads prefix while file_exists()
// resolved the traversal.
$resolved = realpath($base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
$baseNorm = rtrim(str_replace('\\', '/', $base_dir), '/') . '/';

if ($resolved === false
    || strpos(str_replace('\\', '/', $resolved), $baseNorm) !== 0
    || !is_file($resolved)) {
    http_response_code(404);
    die('File not found on server');
}

$filesize = filesize($resolved);
if ($filesize === false) {
    http_response_code(500);
    die('The stored copy of this file could not be read.');
}

$filetype = function_exists('mime_content_type')
    ? assignment_safe_download_mime(mime_content_type($resolved))
    : 'application/octet-stream';

assignment_stream_file($resolved, $filename, $filetype, $filesize);

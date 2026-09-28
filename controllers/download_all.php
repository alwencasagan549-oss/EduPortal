<?php
/**
 * Bulk download of a teacher's submissions as a ZIP.
 *
 * Deliberately bounded: building the archive in a request thread meant one
 * long-lived request held every submission (and its base64 copy) in memory,
 * which exceeds the 256MB memory_limit well before a full class size.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/assignment_management.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'teacher') {
    header('Location: /teacher/login.php');
    exit();
}

$teacher_id = (int) $_SESSION['user_id'];
$teacher_subject = trim((string) ($_SESSION['user_subject'] ?? ''));

$conn = getDBConnection();

if (!assignment_submission_has_column($conn, 'file_type')) {
    http_response_code(503);
    die('Submission storage is not ready. Please contact the administrator.');
}

// Cap the export so one request cannot exhaust memory. Callers needing more
// should page through the archive endpoint.
const DOWNLOAD_ALL_LIMIT = 150;

$scope = assignment_teacher_submission_scope('s', $teacher_id, $teacher_subject);
$stmt = $conn->prepare(
    "SELECT s.id, s.file_path, s.file_content, s.file_type, st.name AS student_name
     FROM submissions s
     LEFT JOIN students st ON s.student_id = st.id
     WHERE " . $scope['sql'] . "
     ORDER BY s.submitted_at DESC
     LIMIT " . DOWNLOAD_ALL_LIMIT
);
$stmt->execute($scope['params']);
$submissions = $stmt->get_result()->fetch_all(PDO::FETCH_ASSOC);

if ($submissions === []) {
    http_response_code(404);
    die('No submissions found for subject: ' . htmlspecialchars($teacher_subject, ENT_QUOTES, 'UTF-8'));
}

$base_dir = realpath(__DIR__ . '/../uploads');
$baseNorm = $base_dir === false ? null : rtrim(str_replace('\\', '/', $base_dir), '/') . '/';

$temp_zip = tempnam(sys_get_temp_dir(), 'eduportal_zip_');
if ($temp_zip === false) {
    http_response_code(500);
    die('Cannot create a temporary file for the archive.');
}

$zip = new ZipArchive();
if ($zip->open($temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($temp_zip);
    http_response_code(500);
    die('Cannot create ZIP file');
}

$added = 0;
$usedNames = [];

try {
    foreach ($submissions as $submission) {
        $storedName = basename(str_replace('\\', '/', (string) $submission['file_path']));
        $studentName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($submission['student_name'] ?? 'student'));

        // Keep names unique; two students can share a filename.
        $base = $studentName . '_' . $storedName;
        $entryName = $base;
        $suffix = 1;
        while (isset($usedNames[$entryName])) {
            $entryName = $base . '_' . $suffix++;
        }
        $usedNames[$entryName] = true;

        $entryPath = null;

        if (!empty($submission['file_content'])) {
            // addFromString needs the bytes in memory; skip rather than risk
            // an OOM on a single oversized row.
            $decoded = base64_decode((string) $submission['file_content'], true);
            if ($decoded === false) {
                continue;
            }
            if ($zip->addFromString($entryName, $decoded) === true) {
                $added++;
            }
            unset($decoded);
            continue;
        }

        $relative = ltrim(str_replace('\\', '/', (string) $submission['file_path']), '/');
        if (strpos($relative, 'uploads/') === 0) {
            $relative = substr($relative, strlen('uploads/'));
        }

        if ($baseNorm === null) {
            continue;
        }

        $resolved = realpath($base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($resolved === false
            || !is_file($resolved)
            || strpos(str_replace('\\', '/', $resolved), $baseNorm) !== 0) {
            continue;
        }

        // addFile streams from disk instead of loading the file into PHP.
        if ($zip->addFile($resolved, $entryName) === true) {
            $added++;
        }
    }

    $zip->close();
} catch (Throwable $exception) {
    @$zip->close();
    @unlink($temp_zip);
    error_log('EduPortal download_all failed: ' . $exception->getMessage());
    http_response_code(500);
    die('The archive could not be built.');
}

if ($added === 0) {
    @unlink($temp_zip);
    http_response_code(404);
    die('None of the matching submission files could be read.');
}

$zipFilename = assignment_safe_download_filename(
    ($teacher_subject !== '' ? $teacher_subject : 'submissions') . '_submissions_' . date('Y-m-d')
) . '.zip';

$size = filesize($temp_zip);
if ($size === false) {
    @unlink($temp_zip);
    http_response_code(500);
    die('The archive could not be read.');
}

if (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($temp_zip);
@unlink($temp_zip);
exit();

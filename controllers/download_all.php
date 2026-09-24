<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/assignment_management.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    header('Location: /teacher/login.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_subject = $_SESSION['user_subject'] ?? '';

// Get submissions for teacher's subject (Auth Shield: RLS Check)
$conn = getDBConnection();

try {
    $submissionColumns = assignment_submission_column_names($conn);
    if (!isset($submissionColumns['file_content'])) {
        if ($conn->getDriverName() === 'mysql') {
            $conn->exec('ALTER TABLE submissions ADD COLUMN file_content LONGTEXT');
        } else {
            $conn->exec('ALTER TABLE submissions ADD COLUMN file_content TEXT DEFAULT NULL');
        }
    }
    if (!isset($submissionColumns['file_type'])) {
        $conn->exec("ALTER TABLE submissions ADD COLUMN file_type VARCHAR(100) DEFAULT 'application/octet-stream'");
    }
} catch (Throwable $e) {
    error_log('EduPortal schema migration error in download_all.php: ' . $e->getMessage());
}

$stmt = $conn->prepare("SELECT s.file_path, s.file_content, s.file_type, st.name as student_name
                       FROM submissions s
                       LEFT JOIN students st ON s.student_id = st.id
                       WHERE (s.teacher_id = ? OR (s.teacher_id IS NULL AND s.subject = ?))");
$stmt->execute([$teacher_id, $teacher_subject]);
$result = $stmt->get_result();
$submissions = $result->fetch_all(PDO::FETCH_ASSOC);

if (empty($submissions)) {
    die('No submissions found for subject: ' . htmlspecialchars($teacher_subject));
}

// Create ZIP file
$zip = new ZipArchive();
$zip_filename = $teacher_subject . '_submissions_' . date('Y-m-d') . '.zip';
$temp_zip = tempnam(sys_get_temp_dir(), 'zip');
unlink($temp_zip);

if ($zip->open($temp_zip, ZipArchive::CREATE) !== TRUE) {
    die('Cannot create ZIP file');
}

$base_dir = realpath(__DIR__ . '/../uploads');

foreach ($submissions as $submission) {
    $file_name = basename($submission['file_path']);
    $student_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $submission['student_name']);
    $new_name = $student_name . '_' . $file_name;

    if (!empty($submission['file_content'])) {
        // Serve from database (for Render compatibility)
        $file_data = base64_decode($submission['file_content']);
        $zip->addFromString($new_name, $file_data);
    } else {
        // Fallback: serve from filesystem
        $relative = ltrim(str_replace('\\', '/', (string) $submission['file_path']), '/');
        if (strpos($relative, 'uploads/') === 0) {
            $relative = substr($relative, strlen('uploads/'));
        }
        $resolved = $base_dir === false ? false : realpath($base_dir . DIRECTORY_SEPARATOR . $relative);
        if ($resolved !== false && $base_dir !== false && strpos(str_replace('\\', '/', $resolved), rtrim(str_replace('\\', '/', $base_dir), '/') . '/') === 0) {
            $zip->addFile($resolved, $new_name);
        }
    }
}

$zip->close();

// Send to browser
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
header('Content-Length: ' . filesize($temp_zip));
readfile($temp_zip);

// Clean up
unlink($temp_zip);
exit();
?>
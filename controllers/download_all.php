<?php
require_once __DIR__ . '/../config/database.php';
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

// Auto-create columns if needed (PostgreSQL + MySQL safe)
try {
    $check = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'submissions' AND column_name = 'file_content'");
    $check->execute();
    $exists = $check->fetchColumn();
    if (!$exists) {
        $conn->exec("ALTER TABLE submissions ADD COLUMN file_content TEXT DEFAULT NULL");
        $conn->exec("ALTER TABLE submissions ADD COLUMN file_type VARCHAR(100) DEFAULT 'application/octet-stream'");
    }
} catch (Throwable $e) {}

// Strengthened Logic: Filter by subject only (since teacher_id is not consistently populated)
$stmt = $conn->prepare("SELECT s.file_path, s.file_content, s.file_type, st.name as student_name
                       FROM submissions s
                       LEFT JOIN students st ON s.student_id = st.id
                       WHERE s.subject = ?");
$stmt->execute([$teacher_subject]);
$result = $stmt->get_result();
$submissions = $result->fetch_all(MYSQLI_ASSOC);

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
        $resolved = realpath(__DIR__ . '/../' . ltrim($submission['file_path'], '/\\'));
        if ($resolved !== false && $base_dir !== false && strpos($resolved, $base_dir) === 0) {
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
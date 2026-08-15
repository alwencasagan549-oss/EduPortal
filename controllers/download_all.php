<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    header('Location: ../teacher/login.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_subject = $_SESSION['user_subject'] ?? '';

// Get submissions for teacher's subject (Auth Shield: RLS Check)
$conn = getDBConnection();
// Strengthened Logic: Filter by subject only (since teacher_id is not consistently populated)
$stmt = $conn->prepare("SELECT s.file_path, st.name as student_name
                       FROM submissions s
                       LEFT JOIN students st ON s.student_id = st.id
                       WHERE s.subject = ?");
$stmt->execute([$teacher_subject]);
$result = $stmt->get_result();
$submissions = $result->fetch_all();

if (empty($submissions)) {
    die('No submissions found for subject: ' . htmlspecialchars($teacher_subject));
}

// Create ZIP file
$zip = new ZipArchive();
$zip_filename = $teacher_subject . '_submissions_' . date('Y-m-d') . '.zip';
$temp_zip = tempnam(sys_get_temp_dir(), 'zip');

if ($zip->open($temp_zip, ZipArchive::CREATE) !== TRUE) {
    die('Cannot create ZIP file');
}

foreach ($submissions as $submission) {
    if (file_exists($submission['file_path'])) {
        $file_name = basename($submission['file_path']);
        $student_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $submission['student_name']);
        $new_name = $student_name . '_' . $file_name;
        $zip->addFile($submission['file_path'], $new_name);
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
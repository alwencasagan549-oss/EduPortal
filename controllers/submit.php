<?php
/**
 * Secure Assignment Submission with RLS
 */
require_once __DIR__ . '/../config/database.php';

// Check if student is logged in
if (!isLoggedIn() || $_SESSION['user_role'] !== 'student') {
    header('Location: ../student/login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        header('Location: ../student/dashboard.php?error=Invalid+security+token');
        exit();
    }
}

// Get form data - use session name instead of POST
$student_name = $_SESSION['user_name'] ?? '';
$subject = trim($_POST['subject'] ?? '');

// Validate inputs
if (empty($student_name) || empty($subject)) {
    header('Location: student_dashboard.php?error=All+fields+are+required');
    exit();
}

// Validate file upload
if (!isset($_FILES['assignment']) || $_FILES['assignment']['error'] !== UPLOAD_ERR_OK) {
    header('Location: student_dashboard.php?error=Please+select+a+file');
    exit();
}

$file = $_FILES['assignment'];

// File validation
$allowed_ext = ['pdf', 'doc', 'docx'];
$max_size = 10 * 1024 * 1024; // 10MB
$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// Check file extension
if (!in_array($file_ext, $allowed_ext)) {
    header('Location: student_dashboard.php?error=Only+PDF,+DOC,+and+DOCX+files+are+allowed');
    exit();
}

// Check file size
if ($file['size'] > $max_size) {
    header('Location: student_dashboard.php?error=File+size+exceeds+10MB+limit');
    exit();
}

// Sanitize filename
$safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
$new_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $student_name) . '_' . $safe_filename;
$upload_path = 'uploads/' . $new_filename;

// Create uploads directory if it doesn't exist
if (!is_dir('uploads')) {
    mkdir('uploads', 0750, true);
}

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
    header('Location: student_dashboard.php?error=Failed+to+save+file');
    exit();
}

// Insert into database
$conn = getDBConnection();
$current_date = date('Y-m-d');
$student_id = $_SESSION['user_id'];
$file_content = base64_encode(file_get_contents($upload_path));
$file_type = mime_content_type($upload_path);
$stmt = $conn->prepare("INSERT INTO submissions (student_id, subject, file_path, submission_date, file_content, file_type) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$student_id, $subject, $upload_path, $current_date, $file_content, $file_type]);

if ($stmt->rowCount() > 0) {
    header('Location: ../student/dashboard.php?success=1');
} else {
    unlink($upload_path);
    header('Location: student_dashboard.php?error=Database+error.+Please+try+again.');
}
exit();
?>
<?php
/**
 * AJAX Handler: Post Broadcast Assignment & Notify
 * Handles file upload, record creation, and bulk queuing for student notifications.
 */

require_once __DIR__ . '/../libs/QueueManager.php';
requireLogin();

header('Content-Type: application/json');

if (getUserRole() !== 'teacher') {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
        exit();
    }
    try {
    $teacher_id = $_SESSION['user_id'] ?? 0;
    $teacher_name = $_SESSION['user_name'] ?? 'Teacher';
    $subject = $_SESSION['user_subject'] ?? '';
    $teacher_email = $_SESSION['user_email'] ?? 'teacher@eduportal.com';
    
    $grade_level = $_POST['grade_level'] ?? '';
    $strand = $_POST['strand'] ?? '';
    $section = $_POST['section'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    // File handling
    if (!isset($_FILES['assignment_file']) || $_FILES['assignment_file']['error'] !== 0) {
        echo json_encode(['success' => false, 'error' => 'Please select a valid file.']);
        exit();
    }
    
    $file = $_FILES['assignment_file'];
    $upload_dir = 'uploads/assignments/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0750, true);
    }
    
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['pdf', 'doc', 'docx', 'txt', 'zip', 'jpg', 'png'];
    
    if (!in_array($file_ext, $allowed_exts)) {
        echo json_encode(['success' => false, 'error' => 'File type not allowed.']);
        exit();
    }
    
    $new_filename = time() . "_" . preg_replace("/[^a-zA-Z0-9]/", "_", $teacher_name) . "_" . $file['name'];
    $file_path = $upload_dir . $new_filename;
    
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        $file_content = base64_encode(file_get_contents($file['tmp_name']));
        $file_type = mime_content_type($file['tmp_name']);

        $conn = getDBConnection();

        // Auto-create columns if needed (PostgreSQL-compatible)
        try {
            $check = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'posted_assignments' AND column_name = 'file_content'");
            if ($check && $check->num_rows === 0) {
                $conn->query("ALTER TABLE posted_assignments ADD COLUMN file_content TEXT DEFAULT NULL");
                $conn->query("ALTER TABLE posted_assignments ADD COLUMN file_type VARCHAR(100) DEFAULT 'application/octet-stream'");
            }
        } catch (Exception $e) {}

        $stmt = $conn->prepare("INSERT INTO posted_assignments (teacher_id, teacher_name, subject, title, description, file_path, grade_level, strand, section, file_content, file_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$teacher_id, $teacher_name, $subject, $title, $description, $file_path, $grade_level, $strand, $section, $file_content, $file_type]);

        // BULK NOTIFICATION LOGIC
        $st_stmt = $conn->prepare("SELECT email, name FROM students WHERE grade_level = ? AND strand = ? AND section = ?");
        $st_stmt->execute([$grade_level, $strand, $section]);
        $students_res = $st_stmt->get_result();

        $student_count = 0;
        while ($student = $students_res->fetch_assoc()) {
            $email_subject = "[EduPortal] New Assignment: $title";
            $email_body = "Hello " . $student['name'] . ",\n\n" .
                         "Your teacher, $teacher_name, has posted a new assignment for $subject (Grade $grade_level - $section).\n\n" .
                         "Title: $title\n\n" .
                         "Log in to your dashboard to download the soft copy.\n\n" .
                         "Regards,\nEduPortal System";

            QueueManager::push('email', [
                'to' => $student['email'],
                'subject' => $email_subject,
                'message' => $email_body,
                'from_name' => PLATFORM_NAME,
                'from_email' => SMTP_USER,
                'smtp_user' => SMTP_USER,
                'smtp_pass' => SMTP_PASS
            ]);
            $student_count++;
        }

        echo json_encode([
            'success' => true,
            'total_notified' => $student_count,
            'target_group' => "$grade_level $strand - $section"
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file.']);
    }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
}
?>

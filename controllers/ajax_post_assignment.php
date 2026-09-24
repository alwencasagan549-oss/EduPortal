<?php
/**
 * AJAX Handler: Post Broadcast Assignment & Notify
 * Handles file upload, record creation, and internal notifications.
 */

require_once __DIR__ . '/../libs/NotificationManager.php';
requireLogin();

header('Content-Type: application/json');

if (getUserRole() !== 'teacher') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Access denied']);
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
        
        $grade_level = $_POST['grade_level'] ?? '';
        $strand = $_POST['strand'] ?? '';
        $section = $_POST['section'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
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
        
        $new_filename = time() . '_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
        $file_path = $upload_dir . $new_filename;

        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            $conn = getDBConnection();

            $stmt = $conn->prepare("INSERT INTO posted_assignments (teacher_id, teacher_name, subject, title, description, file_path, grade_level, strand, section) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$teacher_id, $teacher_name, $subject, $title, $description, $file_path, $grade_level, $strand, $section]);

            $st_stmt = $conn->prepare("SELECT id, name FROM students WHERE grade_level = ? AND strand = ? AND section = ?");
            $st_stmt->execute([$grade_level, $strand, $section]);
            $students_res = $st_stmt->get_result();

            $notified_count = 0;
            while ($student = $students_res->fetch_assoc()) {
                NotificationManager::push($student['id'], 'assignment', 'New Assignment Posted', "Your teacher has posted a new assignment: $title", [
                    'assignment_title' => $title,
                    'teacher_name' => $teacher_name,
                    'subject' => $subject,
                    'grade_level' => $grade_level,
                    'section' => $section,
                    'strand' => $strand
                ]);
                $notified_count++;
            }

            echo json_encode([
                'success' => true,
                'total_notified' => $notified_count,
                'target_group' => "$grade_level $strand - $section"
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file.']);
        }
    } catch (Throwable $e) {
        error_log('EduPortal error in ajax_post_assignment.php: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
}
?>

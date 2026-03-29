<?php
/**
 * AJAX Handler: Push Email Job to Queue
 */

session_start();
require_once '../libs/QueueManager.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = intval($_POST['student_id'] ?? 0);
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    // Get student/teacher details for the job payload
    require_once '../config/database.php';
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT name, email FROM students WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$student || empty($subject) || empty($message)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid data']);
        exit;
    }

    $payload = [
        'to' => $student['email'],
        'subject' => $subject,
        'message' => $message,
        'from_name' => $_SESSION['user_name'],
        'from_email' => $_SESSION['user_email'],
        'smtp_user' => SMTP_USER,
        'smtp_pass' => SMTP_PASS
    ];

    $job_id = QueueManager::push('email', $payload);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'job_id' => $job_id]);
    exit;
}
?>

<?php
/**
 * AJAX Handler: Create internal notification for a student
 * Replaces email-based contact with in-app notification.
 */

require_once __DIR__ . '/../libs/NotificationManager.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        rotate_csrf();
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid security token.', 'csrf_token' => csrf_token()]);
        exit();
    }
    rotate_csrf();
    $student_id = intval($_POST['student_id'] ?? 0);
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (!$student_id || empty($subject) || empty($message)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid data', 'csrf_token' => csrf_token()]);
        exit;
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT name FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->get_result()->fetch_assoc();

    if (!$student) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Student not found', 'csrf_token' => csrf_token()]);
        exit;
    }

    $sender_name = $_SESSION['user_name'] ?? 'Teacher';

    $notification_id = NotificationManager::push($student_id, 'message', $subject, $message, [
        'sender_name' => $sender_name,
        'sender_id' => $_SESSION['user_id'] ?? 0
    ]);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'notification_id' => $notification_id, 'csrf_token' => csrf_token()]);
    exit;
}

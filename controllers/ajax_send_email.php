<?php
/**
 * AJAX Handler: Create internal notification for a student
 * Replaces email-based contact with in-app notification.
 *
 * Teacher-only. Every field is length-bounded to match the target columns
 * (notifications.title is VARCHAR(255), notifications.message is TEXT).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/assignment_management.php';
require_once __DIR__ . '/../libs/NotificationManager.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (getUserRole() !== 'teacher') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

if (!validate_csrf($_POST['csrf_token'] ?? '')) {
    rotate_csrf();
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token.', 'csrf_token' => csrf_token()]);
    exit();
}
rotate_csrf();

$student_id = assignment_id($_POST['student_id'] ?? null);
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($student_id === null || $subject === '' || $message === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid data', 'csrf_token' => csrf_token()]);
    exit;
}

if (assignment_subject_length($subject) > 255) {
    http_response_code(422);
    echo json_encode(['error' => 'Subject must be 255 characters or fewer.', 'csrf_token' => csrf_token()]);
    exit;
}

if (assignment_subject_length($message) > 5000) {
    http_response_code(422);
    echo json_encode(['error' => 'Message must be 5000 characters or fewer.', 'csrf_token' => csrf_token()]);
    exit;
}

$sender_id = (int) ($_SESSION['user_id'] ?? 0);
$conn = getDBConnection();

// Abuse guard: cap how many direct messages one student can receive per minute.
$recentStmt = $conn->prepare(
    "SELECT COUNT(*) AS recent_count
     FROM notifications
     WHERE type = 'message' AND user_id = ? AND created_at > ?"
);
$recentStmt->execute([$student_id, gmdate('Y-m-d H:i:s', time() - 60)]);
$recentCount = (int) ($recentStmt->get_result()->fetch_assoc()['recent_count'] ?? 0);
if ($recentCount >= 10) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'Too many messages sent. Please wait a moment.', 'csrf_token' => csrf_token()]);
    exit;
}

$stmt = $conn->prepare("SELECT name FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    http_response_code(404);
    echo json_encode(['error' => 'Student not found', 'csrf_token' => csrf_token()]);
    exit;
}

$sender_name = isset($_SESSION['user_name']) && is_string($_SESSION['user_name'])
    ? $_SESSION['user_name']
    : 'Teacher';

try {
    $notification_id = NotificationManager::push($student_id, 'message', $subject, $message, [
        'sender_name' => $sender_name,
        'sender_id' => $sender_id
    ]);
} catch (Throwable $exception) {
    error_log('EduPortal teacher notification failed: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'The message could not be delivered.', 'csrf_token' => csrf_token()]);
    exit;
}

echo json_encode(['success' => true, 'notification_id' => $notification_id, 'csrf_token' => csrf_token()]);

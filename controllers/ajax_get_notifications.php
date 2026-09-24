<?php
/**
 * AJAX Handler: Get notifications for the current user
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/NotificationManager.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'list';

if ($action === 'count') {
    echo json_encode(['count' => NotificationManager::getUnreadCount($user_id)]);
} elseif ($action === 'list') {
    $notifications = NotificationManager::getForUser($user_id, 50);
    echo json_encode(['notifications' => $notifications]);
} elseif ($action === 'mark_read') {
    $notification_id = intval($_POST['notification_id'] ?? 0);
    NotificationManager::markAsRead($user_id, $notification_id);
    echo json_encode(['success' => true]);
} elseif ($action === 'mark_all_read') {
    NotificationManager::markAllAsRead($user_id);
    echo json_encode(['success' => true]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}

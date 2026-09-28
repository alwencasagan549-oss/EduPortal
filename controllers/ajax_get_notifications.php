<?php
/**
 * AJAX Handler: Notifications for the current user.
 *
 * Read actions accept GET; state-changing actions require POST *and* a valid
 * CSRF token. The mutation branches previously had neither, so a cross-site
 * form could mark a victim's notifications read.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/assignment_management.php';
require_once __DIR__ . '/../libs/NotificationManager.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$mutating = in_array($action, ['mark_read', 'mark_all_read'], true);

if ($mutating) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit();
    }
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token', 'csrf_token' => csrf_token()]);
        exit();
    }
}

switch ($action) {
    case 'count':
        echo json_encode(['success' => true, 'count' => NotificationManager::getUnreadCount($user_id)]);
        break;

    case 'list':
        echo json_encode([
            'success' => true,
            'notifications' => NotificationManager::getForUser($user_id, 50)
        ]);
        break;

    case 'mark_read':
        $notification_id = assignment_id($_POST['notification_id'] ?? null);
        if ($notification_id === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid notification id']);
            exit();
        }
        // Scoped by user_id inside the manager, so this cannot touch another
        // account's rows.
        NotificationManager::markAsRead($user_id, $notification_id);
        echo json_encode(['success' => true]);
        break;

    case 'mark_all_read':
        NotificationManager::markAllAsRead($user_id);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}

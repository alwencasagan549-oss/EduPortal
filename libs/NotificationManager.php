<?php
/**
 * EduPortal Notification Manager
 * Handles internal system notifications (replaces SMTP email).
 */

require_once __DIR__ . '/../config/database.php';

class NotificationManager {
    public static function push($user_id, $type, $title, $message, $data = []) {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "INSERT INTO notifications (user_id, type, title, message, data) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $user_id,
            $type,
            $title,
            $message,
            json_encode($data)
        ]);
        return (int)$conn->getPDO()->lastInsertId();
    }

    public static function getForUser($user_id, $limit = 50) {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->execute([$user_id, $limit]);
        return $stmt->get_result()->fetch_all();
    }

    public static function markAsRead($user_id, $notification_id) {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$notification_id, $user_id]);
    }

    public static function markAllAsRead($user_id) {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE"
        );
        $stmt->execute([$user_id]);
    }

    public static function getUnreadCount($user_id) {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE"
        );
        $stmt->execute([$user_id]);
        $result = $stmt->get_result()->fetch_assoc();
        return (int)($result['count'] ?? 0);
    }
}

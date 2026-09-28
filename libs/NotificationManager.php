<?php
/**
 * EduPortal Notification Manager
 * Handles internal system notifications (replaces SMTP email).
 */

require_once __DIR__ . '/../config/database.php';

class NotificationManager {
    /**
     * @return int The new notification id, or 0 when the insert could not be
     *             confirmed. lastInsertId() is driver-specific on PostgreSQL
     *             (it needs a sequence name), so RETURNING is used instead.
     */
    public static function push($user_id, $type, $title, $message, $data = []) {
        $conn = getDBConnection();
        $isMysql = method_exists($conn, 'getDriverName') && $conn->getDriverName() === 'mysql';

        $sql = $isMysql
            ? 'INSERT INTO notifications (user_id, type, title, message, data) VALUES (?, ?, ?, ?, ?)'
            : 'INSERT INTO notifications (user_id, type, title, message, data) VALUES (?, ?, ?, ?, ?) RETURNING id';

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $user_id,
            $type,
            $title,
            $message,
            json_encode($data, JSON_UNESCAPED_SLASHES)
        ]);

        if ($isMysql) {
            return (int) $conn->getPDO()->lastInsertId();
        }

        $id = $stmt->fetchColumn();
        return $id === false ? 0 : (int) $id;
    }

    /**
     * Sends one notification per recipient in a single round-trip.
     *
     * The per-student insert loop this replaces cost one round-trip per
     * student against a remote database, and a mid-loop failure left the class
     * permanently un-notified.
     *
     * @return int number of recipients notified
     */
    public static function pushMany(array $userIds, $type, $title, $message, $data = []): int {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return 0;
        }

        $conn = getDBConnection();
        $isMysql = method_exists($conn, 'getDriverName') && $conn->getDriverName() === 'mysql';

        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES);

        if ($isMysql) {
            $values = [];
            foreach ($userIds as $id) {
                $values[] = $id;
                $values[] = $type;
                $values[] = $title;
                $values[] = $message;
                $values[] = $payload;
            }
            $rowPlaceholders = '(' . implode(', ', array_fill(0, 5, '?')) . ')';
            $stmt = $conn->prepare(
                "INSERT INTO notifications (user_id, type, title, message, data) VALUES {$rowPlaceholders}"
            );
        } else {
            $values = array_merge($userIds, [$type, $title, $message, $payload]);
            // unnest(?) turns the id array into a set the INSERT ... SELECT reads.
            $stmt = $conn->prepare(
                "INSERT INTO notifications (user_id, type, title, message, data)
                 SELECT unnest(?::int[]), ?, ?, ?, ?::jsonb"
            );
        }

        $stmt->execute($values);
        return $stmt->rowCount();
    }

    public static function getForUser($user_id, $limit = 50) {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?'
        );
        $stmt->execute([$user_id, (int) $limit]);
        return $stmt->get_result()->fetch_all();
    }

    public static function markAsRead($user_id, $notification_id) {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            'UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ? AND is_read = FALSE'
        );
        $stmt->execute([$notification_id, $user_id]);
    }

    public static function markAllAsRead($user_id) {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            'UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE'
        );
        $stmt->execute([$user_id]);
    }

    public static function getUnreadCount($user_id) {
        $conn = getDBConnection();
        // The column must be aliased: PostgreSQL labels an unaliased COUNT(*)
        // as "count" and MySQL as "COUNT(*)", so the unaliased lookup silently
        // returned 0 on MySQL.
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = FALSE'
        );
        $stmt->execute([$user_id]);
        $result = $stmt->get_result()->fetch_assoc();
        return (int) ($result['unread_count'] ?? 0);
    }
}

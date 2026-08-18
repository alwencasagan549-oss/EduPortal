<?php
/**
 * Auto-Cleanup Controller
 * Deletes old file_content from database when storage exceeds 80%
 * Only removes files older than 1 week; keeps the latest uploads
 */

require_once __DIR__ . '/../config/database.php';
requireLogin();

if (getUserRole() !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    exit('Admin access only');
}

header('Content-Type: application/json');

try {
    $conn = getDBConnection();
    $db_name = getenv('DB_NAME') ?: 'edu_portal';

    // Get total database size in bytes
    $stmt = $conn->prepare("SELECT SUM(data_length + index_length) AS total_size FROM information_schema.TABLES WHERE table_schema = ?");
    $stmt->execute([$db_name]);
    $row = $stmt->get_result()->fetch_assoc();
    $db_size = $row['total_size'] ?? 0;

    // Get database size limit (Render free tier: 1GB = 1073741824 bytes)
    // Adjust this value based on your Render plan
    $db_limit = 1073741824; // 1GB
    $threshold = $db_limit * 0.8; // 80%

    $deleted_assignments = 0;
    $deleted_submissions = 0;
    $freed_bytes = 0;

    if ($db_size > $threshold) {
        $one_week_ago = date('Y-m-d H:i:s', strtotime('-1 week'));

        // Clean old assignment file_content (keep files < 1 week old)
        $stmt = $conn->prepare("SELECT id, file_content, file_path, created_at FROM posted_assignments WHERE file_content IS NOT NULL AND file_content != '' AND created_at < ?");
        $stmt->execute([$one_week_ago]);
        $old_assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($old_assignments as $assignment) {
            $content_size = strlen($assignment['file_content']);
            $freed_bytes += $content_size;

            // Remove file_content from DB
            $stmt = $conn->prepare("UPDATE posted_assignments SET file_content = NULL, file_type = NULL WHERE id = ?");
            $stmt->execute([$assignment['id']]);

            // Delete physical file if it exists
            $resolved = realpath(__DIR__ . '/../' . ltrim($assignment['file_path'], '/\\'));
            if ($resolved && file_exists($resolved)) {
                @unlink($resolved);
            }

            $deleted_assignments++;
        }

        // Clean old submission file_content (keep files < 1 week old)
        $stmt = $conn->prepare("SELECT id, file_content, file_path, submitted_at FROM submissions WHERE file_content IS NOT NULL AND file_content != '' AND submitted_at < ?");
        $stmt->execute([$one_week_ago]);
        $old_submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($old_submissions as $submission) {
            $content_size = strlen($submission['file_content']);
            $freed_bytes += $content_size;

            // Remove file_content from DB
            $stmt = $conn->prepare("UPDATE submissions SET file_content = NULL, file_type = NULL WHERE id = ?");
            $stmt->execute([$submission['id']]);

            // Delete physical file if it exists
            $resolved = realpath(__DIR__ . '/../' . ltrim($submission['file_path'], '/\\'));
            if ($resolved && file_exists($resolved)) {
                @unlink($resolved);
            }

            $deleted_submissions++;
        }
    }

    echo json_encode([
        'success' => true,
        'db_size_mb' => round($db_size / 1048576, 2),
        'db_limit_mb' => round($db_limit / 1048576, 2),
        'threshold_mb' => round($threshold / 1048576, 2),
        'triggered' => $db_size > $threshold,
        'deleted_assignments' => $deleted_assignments,
        'deleted_submissions' => $deleted_submissions,
        'freed_mb' => round($freed_bytes / 1048576, 2)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

<?php
/**
 * AJAX Handler: Traffic Monitor
 * Checks if there are pending jobs in the system queue.
 */

require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

// Check for pending or processing jobs (SQL Shield: Standardized)
$stmt = $conn->prepare("SELECT COUNT(*) as traffic_count FROM jobs WHERE status IN ('pending', 'processing')");
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

header('Content-Type: application/json');
echo json_encode([
    'has_traffic' => $data['traffic_count'] > 0,
    'count' => $data['traffic_count']
]);
?>

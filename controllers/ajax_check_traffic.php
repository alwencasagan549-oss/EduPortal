<?php
/**
 * AJAX Handler: Traffic Monitor
 *
 * Reports whether background jobs are pending. Requires a signed-in session:
 * this endpoint used to be reachable anonymously, which both leaked queue depth
 * and opened a database connection on every poll.
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'has_traffic' => false, 'count' => 0]);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed', 'has_traffic' => false, 'count' => 0]);
    exit();
}

try {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS traffic_count FROM jobs WHERE status IN ('pending', 'processing')"
    );
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['traffic_count'] ?? 0);

    echo json_encode(['success' => true, 'has_traffic' => $count > 0, 'count' => $count]);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'Traffic monitoring is unavailable.', 'has_traffic' => false, 'count' => 0]);
}

<?php
/**
 * Legacy job-queue endpoint.
 *
 * The queue is no longer driven from the browser. Jobs are executed by
 * worker.php (see migrations/run.php for the schema). This endpoint is kept as
 * a harmless authenticated stub so stale clients do not receive an error.
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

echo json_encode(['success' => true, 'processed' => 0, 'results' => []]);

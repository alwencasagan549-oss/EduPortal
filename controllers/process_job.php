<?php
/**
 * AJAX Handler: Background Job Processor
 */

require_once '../config/database.php';
require_once '../libs/QueueManager.php';

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$action = $_GET['action'] ?? '';

if ($action === 'push') {
    // We already handle push in the main PHP scripts for reliability.
} elseif ($action === 'process') {
    $results = [];
    while (($result = QueueManager::processNext()) !== null) {
        $results[] = $result;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'processed' => count($results),
        'results' => $results
    ]);
} elseif ($action === 'status') {
    $job_id = intval($_GET['job_id'] ?? 0);
    $status = QueueManager::getStatus($job_id);
    header('Content-Type: application/json');
    echo json_encode($status);
}
?>

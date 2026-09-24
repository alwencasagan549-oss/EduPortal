<?php
/**
 * AJAX Handler: Get Upload Status / Resume
 * Returns completed chunks and remaining presigned URLs for resumable uploads.
 */
require_once __DIR__ . '/../config/database.php';

requireLogin();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

if (!validate_csrf($_GET['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit();
}

try {
    $userId = $_SESSION['user_id'] ?? 0;
    $userRole = $_SESSION['user_role'] ?? '';
    $uploadId = trim($_GET['uploadId'] ?? '');

    if (empty($uploadId)) {
        echo json_encode(['success' => false, 'error' => 'Upload ID required']);
        exit();
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare("
        SELECT * FROM upload_sessions
        WHERE upload_id = ? AND user_id = ? AND user_role = ?
        AND status NOT IN ('completed', 'cancelled', 'aborted')
    ");
    $stmt->execute([$uploadId, $userId, $userRole]);
    $session = $stmt->fetch_assoc();

    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Upload session not found or expired']);
        exit();
    }

    if (new DateTime($session['expires_at']) < new DateTime()) {
        $stmt = $conn->prepare("UPDATE upload_sessions SET status = 'expired' WHERE upload_id = ?");
        $stmt->execute([$uploadId]);

        echo json_encode(['success' => false, 'error' => 'Upload session expired']);
        exit();
    }

    $allPresignedUrls = json_decode($session['presigned_urls'] ?? '[]', true) ?: [];
    $completedChunks = $session['completed_chunks'] ?? [];
    $remainingUrls = [];

    foreach ($allPresignedUrls as $index => $url) {
        $partNumber = $index + 1;
        if (!in_array($partNumber, $completedChunks, true)) {
            $remainingUrls[] = $url;
        }
    }

    echo json_encode([
        'success' => true,
        'uploadId' => $session['upload_id'],
        's3UploadId' => $session['s3_upload_id'],
        'objectKey' => $session['object_key'],
        'chunkSize' => (int)$session['chunk_size'],
        'totalChunks' => (int)$session['total_chunks'],
        'completedChunks' => $completedChunks,
        'remainingPresignedUrls' => $remainingUrls,
        'expiresAt' => $session['expires_at'],
        'status' => $session['status'],
        'originalFilename' => $session['original_filename'],
    ]);
} catch (Throwable $e) {
    error_log('EduPortal upload status error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

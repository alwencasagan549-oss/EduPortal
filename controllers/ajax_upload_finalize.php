<?php
/**
 * AJAX Handler: Finalize Multipart Upload
 * Receives completed chunks with ETags, completes the S3 multipart upload,
 * and updates the upload session record.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/ObjectStorageService.php';

use EduPortal\ObjectStorageService;

requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

if (!validate_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit();
}

try {
    $userId = $_SESSION['user_id'] ?? 0;
    $userRole = $_SESSION['user_role'] ?? '';
    $uploadId = trim($_POST['uploadId'] ?? '');
    $chunksJson = $_POST['chunks'] ?? '[]';

    if (empty($uploadId)) {
        echo json_encode(['success' => false, 'error' => 'Upload ID required']);
        exit();
    }

    $chunks = json_decode($chunksJson, true);
    if (!is_array($chunks) || empty($chunks)) {
        echo json_encode(['success' => false, 'error' => 'No chunks provided']);
        exit();
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare("
        SELECT * FROM upload_sessions
        WHERE upload_id = ? AND user_id = ? AND user_role = ?
    ");
    $stmt->execute([$uploadId, $userId, $userRole]);
    $session = $stmt->fetch_assoc();

    if (!$session) {
        echo json_encode(['success' => false, 'error' => 'Upload session not found']);
        exit();
    }

    $objectKey = $session['object_key'];
    $s3UploadId = $session['s3_upload_id'];

    $parts = [];
    foreach ($chunks as $chunk) {
        if (!isset($chunk['partNumber']) || !isset($chunk['etag'])) {
            continue;
        }
        $parts[] = [
            'PartNumber' => (int)$chunk['partNumber'],
            'ETag' => $chunk['etag'],
        ];
    }

    if (empty($parts)) {
        echo json_encode(['success' => false, 'error' => 'No valid chunks provided']);
        exit();
    }

    $storage = ObjectStorageService::fromEnv();
    $result = $storage->completeMultipartUpload($objectKey, $s3UploadId, $parts);

    $finalUrl = $result['location'];
    $storedFilename = $session['stored_filename'];

    $stmt = $conn->prepare("
        UPDATE upload_sessions
        SET status = 'completed', object_key = ?, updated_at = CURRENT_TIMESTAMP
        WHERE upload_id = ?
    ");
    $stmt->execute([$result['object_key'], $uploadId]);

    echo json_encode([
        'success' => true,
        'uploadId' => $uploadId,
        'objectKey' => $result['object_key'],
        'url' => $finalUrl,
        'storedFilename' => $storedFilename,
        'originalFilename' => $session['original_filename'],
        'size' => (int)$session['file_size'],
        'mimeType' => $session['mime_type'],
    ]);
} catch (Throwable $e) {
    error_log('EduPortal upload finalize error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

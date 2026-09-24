<?php
/**
 * AJAX Handler: Finalize Multipart Upload
 * Receives completed chunks with ETags, completes the S3 multipart upload,
 * and updates the upload session record.
 */
require_once __DIR__ . '/../config/database.php';

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoloadPath)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server configuration error: object storage SDK not installed. Run composer install.']);
    exit();
}

require_once $autoloadPath;
if (!class_exists(\Aws\S3\S3Client::class)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Server configuration error: Cloudflare R2 S3 SDK is not installed.']);
    exit();
}

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
    $seenPartNumbers = [];

    foreach ($chunks as $chunk) {
        $partNumber = filter_var($chunk['partNumber'] ?? null, FILTER_VALIDATE_INT);
        $etag = trim((string)($chunk['etag'] ?? ''));

        if ($partNumber === false || $partNumber < 1 || $etag === '') {
            echo json_encode(['success' => false, 'error' => 'Each uploaded part must include a valid part number and ETag.']);
            exit();
        }

        if (isset($seenPartNumbers[$partNumber])) {
            echo json_encode(['success' => false, 'error' => 'Duplicate uploaded part number.']);
            exit();
        }

        $seenPartNumbers[$partNumber] = true;
        $parts[] = [
            'PartNumber' => $partNumber,
            'ETag' => $etag,
        ];
    }

    $expectedPartCount = (int)$session['total_chunks'];
    if ($expectedPartCount > 0 && count($parts) !== $expectedPartCount) {
        echo json_encode(['success' => false, 'error' => 'Not all uploaded parts were returned for completion.']);
        exit();
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

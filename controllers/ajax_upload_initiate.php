<?php
/**
 * AJAX Handler: Initiate Multipart Upload
 * Returns presigned URLs for each chunk.
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
    $filename = trim($_POST['filename'] ?? '');
    $filesize = (int)($_POST['filesize'] ?? 0);
    $mimeType = trim($_POST['mimetype'] ?? 'application/octet-stream');

    if (empty($filename) || $filesize <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid file information']);
        exit();
    }

    $allowedMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'image/jpeg',
        'image/png',
        'video/mp4',
        'text/plain',
    ];

    $allowedExtensions = ['pdf', 'doc', 'docx', 'zip', 'jpg', 'jpeg', 'png', 'mp4', 'txt'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        echo json_encode(['success' => false, 'error' => 'File type not allowed']);
        exit();
    }

    $maxSize = 500 * 1024 * 1024; // 500MB
    if ($filesize > $maxSize) {
        echo json_encode(['success' => false, 'error' => 'File size exceeds 500MB limit']);
        exit();
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $verifiedMime = $mimeType;
    finfo_close($finfo);

    if (!in_array($verifiedMime, $allowedMimes, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type detected']);
        exit();
    }

    $uploadId = bin2hex(random_bytes(16));
    $chunkSize = calculateChunkSize($filesize);
    $totalChunks = (int)ceil($filesize / $chunkSize);

    if ($totalChunks > 10000) {
        echo json_encode(['success' => false, 'error' => 'File too large for chunked upload']);
        exit();
    }

    $storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;
    $objectKey = trim(ObjectStorageService::fromEnv()->getUploadPrefix(), '/') . '/' . $storedFilename;

    $conn = getDBConnection();
    $stmt = $conn->prepare("
        INSERT INTO upload_sessions
            (upload_id, user_id, user_role, original_filename, stored_filename, file_size, mime_type, chunk_size, total_chunks, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $expiresAt = date('Y-m-d H:i:s', time() + 300);
    $stmt->execute([
        $uploadId,
        $userId,
        $userRole,
        $filename,
        $storedFilename,
        $filesize,
        $verifiedMime,
        $chunkSize,
        $totalChunks,
        $expiresAt,
    ]);

    $storage = ObjectStorageService::fromEnv();
    $s3UploadId = $storage->initiateMultipartUpload($objectKey, $verifiedMime);

    $presignedUrls = [];
    for ($i = 1; $i <= $totalChunks; $i++) {
        $presignedUrls[] = $storage->generatePresignedUploadUrl($objectKey, $s3UploadId, $i, $chunkSize);
    }

    $stmt = $conn->prepare("
        UPDATE upload_sessions SET presigned_urls = ?, object_key = ?, s3_upload_id = ? WHERE upload_id = ?
    ");
    $stmt->execute([json_encode($presignedUrls), $objectKey, $s3UploadId, $uploadId]);

    echo json_encode([
        'success' => true,
        'uploadId' => $uploadId,
        's3UploadId' => $s3UploadId,
        'chunkSize' => $chunkSize,
        'totalChunks' => $totalChunks,
        'presignedUrls' => $presignedUrls,
        'expiresAt' => $expiresAt,
        'objectKey' => $objectKey,
    ]);
} catch (Throwable $e) {
    error_log('EduPortal upload initiate error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

function calculateChunkSize(int $fileSize): int
{
    if ($fileSize <= 10 * 1024 * 1024) {
        return $fileSize;
    }
    if ($fileSize <= 100 * 1024 * 1024) {
        return 5 * 1024 * 1024;
    }
    return 8 * 1024 * 1024;
}

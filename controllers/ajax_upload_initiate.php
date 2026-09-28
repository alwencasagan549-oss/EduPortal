<?php
/**
 * AJAX Handler: Initiate Multipart Upload
 * Returns presigned URLs for each chunk.
 *
 * The declared MIME type here is client-supplied and is treated as a hint
 * only. Authoritative content verification happens in ajax_upload_finalize.php
 * against the assembled object's real bytes.
 */
require_once __DIR__ . '/upload_endpoint.php';

upload_endpoint_boot('POST');
upload_endpoint_require_csrf();

$maxSize = 500 * 1024 * 1024; // 500MB
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

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = (string) ($_SESSION['user_role'] ?? '');
$filename = trim((string) ($_POST['filename'] ?? ''));
$filesize = (int) ($_POST['filesize'] ?? 0);
$declaredMime = strtolower(trim((string) ($_POST['mimetype'] ?? '')));

if ($filename === '' || $filesize <= 0) {
    upload_endpoint_fail(422, 'Invalid file information');
}
if ($filesize > $maxSize) {
    upload_endpoint_fail(413, 'File size exceeds 500MB limit');
}
if (assignment_subject_length($filename) > 255) {
    upload_endpoint_fail(422, 'Filename must be 255 characters or fewer');
}

$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($extension, $allowedExtensions, true)) {
    upload_endpoint_fail(415, 'File type not allowed');
}
if ($declaredMime === '' || !in_array($declaredMime, $allowedMimes, true)) {
    upload_endpoint_fail(415, 'Invalid file type detected');
}

// Resource guard: a client must not be able to open unbounded multipart
// uploads by looping on this endpoint.
$recentStmt = getDBConnection()->prepare(
    'SELECT COUNT(*) AS recent_count FROM upload_sessions
     WHERE user_id = ? AND user_role = ? AND created_at > ?'
);
$recentStmt->execute([
    $userId,
    $userRole,
    gmdate('Y-m-d H:i:s', time() - 3600),
]);
if ((int) ($recentStmt->get_result()->fetch_assoc()['recent_count'] ?? 0) >= 60) {
    header('Retry-After: 3600');
    upload_endpoint_fail(429, 'Too many uploads started. Please try again later.');
}

$chunkSize = calculateChunkSize($filesize);
$totalChunks = (int) ceil($filesize / $chunkSize);

// S3/R2 require every part except the last to be at least 5MB. calculateChunkSize
// already guarantees that, so the cap below is a hard backstop rather than the
// primary guard; 10000 is far above anything the chunk sizing can produce.
if ($totalChunks < 1 || $totalChunks > 10000) {
    upload_endpoint_fail(413, 'File too large for chunked upload');
}

$uploadId = bin2hex(random_bytes(16));
$storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;

try {
    $storage = upload_endpoint_object_storage();
    $objectKey = trim($storage->getUploadPrefix(), '/') . '/' . $storedFilename;
    $s3UploadId = $storage->initiateMultipartUpload($objectKey, $declaredMime);

    $presignedUrls = [];
    for ($i = 1; $i <= $totalChunks; $i++) {
        $presignedUrls[] = $storage->generatePresignedUploadUrl($objectKey, $s3UploadId, $i, $chunkSize);
    }

    $expiresAt = gmdate('Y-m-d H:i:s', time() + 300);
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        'INSERT INTO upload_sessions
            (upload_id, user_id, user_role, original_filename, stored_filename, file_size,
             mime_type, chunk_size, total_chunks, completed_chunks, presigned_urls,
             object_key, s3_upload_id, status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $uploadId,
        $userId,
        $userRole,
        $filename,
        $storedFilename,
        $filesize,
        $declaredMime,
        $chunkSize,
        $totalChunks,
        '[]',
        json_encode($presignedUrls),
        $objectKey,
        $s3UploadId,
        'initiated',
        $expiresAt,
    ]);
} catch (Throwable $e) {
    error_log('EduPortal upload initiate error: ' . $e->getMessage());
    if (isset($s3UploadId, $objectKey)) {
        // Do not leave a paid-for multipart upload dangling.
        $storage->abortMultipartUpload($objectKey, $s3UploadId);
    }
    upload_endpoint_fail(500, 'The upload could not be started. Please try again.');
}

upload_endpoint_succeed([
    'uploadId' => $uploadId,
    's3UploadId' => $s3UploadId,
    'chunkSize' => $chunkSize,
    'totalChunks' => $totalChunks,
    'presignedUrls' => $presignedUrls,
    'expiresAt' => $expiresAt,
    'objectKey' => $objectKey,
]);

function calculateChunkSize(int $fileSize): int
{
    // S3 requires all non-final parts to be >= 5 MiB, so a 1 MiB part size
    // would be rejected. 8 MiB keeps 500MB uploads at ~63 parts.
    if ($fileSize <= 10 * 1024 * 1024) {
        return $fileSize;
    }
    if ($fileSize <= 100 * 1024 * 1024) {
        return 5 * 1024 * 1024;
    }
    return 8 * 1024 * 1024;
}

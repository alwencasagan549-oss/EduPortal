<?php
/**
 * AJAX Handler: Finalize Multipart Upload
 * Receives completed chunks with ETags, completes the S3 multipart upload,
 * verifies the stored bytes, and updates the upload session record.
 */
require_once __DIR__ . '/upload_endpoint.php';

upload_endpoint_boot('POST');
upload_endpoint_require_csrf();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = (string) ($_SESSION['user_role'] ?? '');
$uploadId = trim((string) ($_POST['uploadId'] ?? ''));
$chunksJson = $_POST['chunks'] ?? '[]';

if ($uploadId === '') {
    upload_endpoint_fail(422, 'Upload ID required');
}

$chunks = is_string($chunksJson) ? json_decode($chunksJson, true) : $chunksJson;
if (!is_array($chunks) || $chunks === []) {
    upload_endpoint_fail(422, 'No chunks provided');
}

$conn = getDBConnection();
$stmt = $conn->prepare(
    "SELECT * FROM upload_sessions
     WHERE upload_id = ? AND user_id = ? AND user_role = ?
       AND status NOT IN ('completed', 'cancelled', 'aborted')"
);
$stmt->execute([$uploadId, $userId, $userRole]);
$session = $stmt->fetch_assoc();

if (!$session) {
    upload_endpoint_fail(404, 'Upload session not found');
}
if (strtotime((string) $session['expires_at']) < time()) {
    $conn->prepare("UPDATE upload_sessions SET status = 'expired' WHERE upload_id = ?")->execute([$uploadId]);
    upload_endpoint_fail(410, 'Upload session expired');
}

$objectKey = (string) ($session['object_key'] ?? '');
$s3UploadId = (string) ($session['s3_upload_id'] ?? '');
$totalChunks = (int) $session['total_chunks'];

$parts = [];
$seenPartNumbers = [];

foreach ($chunks as $chunk) {
    if (!is_array($chunk)) {
        upload_endpoint_fail(422, 'Each uploaded part must include a valid part number and ETag.');
    }

    $partNumber = filter_var($chunk['partNumber'] ?? null, FILTER_VALIDATE_INT);
    $etag = trim((string) ($chunk['etag'] ?? ''));

    if ($partNumber === false || $partNumber < 1 || $partNumber > $totalChunks || $etag === '') {
        upload_endpoint_fail(422, 'Each uploaded part must include a valid part number and ETag.');
    }
    if (isset($seenPartNumbers[$partNumber])) {
        upload_endpoint_fail(422, 'Duplicate uploaded part number.');
    }

    $seenPartNumbers[$partNumber] = true;
    $parts[] = [
        'PartNumber' => $partNumber,
        'ETag' => $etag,
    ];
}

if (count($parts) !== $totalChunks) {
    upload_endpoint_fail(422, 'Not all uploaded parts were returned for completion.');
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

$storage = upload_endpoint_object_storage();
$abortOnFailure = static function () use ($storage, $objectKey, $s3UploadId, $uploadId, $conn): void {
    if ($objectKey !== '' && $s3UploadId !== '') {
        $storage->abortMultipartUpload($objectKey, $s3UploadId);
    }
    $conn->prepare("UPDATE upload_sessions SET status = 'aborted' WHERE upload_id = ?")->execute([$uploadId]);
};

try {
    $result = $storage->completeMultipartUpload($objectKey, $s3UploadId, $parts);
} catch (Throwable $e) {
    error_log('EduPortal upload finalize error: ' . $e->getMessage());
    $conn->prepare("UPDATE upload_sessions SET status = 'failed', error_message = ? WHERE upload_id = ?")
        ->execute([substr($e->getMessage(), 0, 500), $uploadId]);
    upload_endpoint_fail(502, 'The uploaded parts could not be assembled. Please retry the upload.');
}

// Authoritative check: the declared type came from the client, so inspect the
// bytes that were actually stored before accepting the upload.
try {
    $detectedMime = strtolower($storage->detectContentType($objectKey));
    $sizeCheck = $storage->headObject($objectKey);
    $storedSize = (int) ($sizeCheck['ContentLength'] ?? 0);
} catch (Throwable $e) {
    error_log('EduPortal upload verification error: ' . $e->getMessage());
    $abortOnFailure();
    upload_endpoint_fail(502, 'The stored upload could not be verified.');
}

if (!in_array($detectedMime, $allowedMimes, true)) {
    error_log("EduPortal rejected upload {$uploadId}: detected {$detectedMime}");
    $storage->deleteObject($objectKey);
    $abortOnFailure();
    upload_endpoint_fail(415, 'The uploaded file content is not a permitted type.');
}

if ($storedSize !== (int) $session['file_size']) {
    error_log("EduPortal rejected upload {$uploadId}: expected {$session['file_size']} bytes, got {$storedSize}");
    $storage->deleteObject($objectKey);
    $abortOnFailure();
    upload_endpoint_fail(422, 'The uploaded file size does not match what was declared.');
}

$conn->prepare(
    "UPDATE upload_sessions
     SET status = 'completed', object_key = ?, mime_type = ?,
         completed_chunks = ?, updated_at = CURRENT_TIMESTAMP
     WHERE upload_id = ?"
)->execute([
    $result['object_key'],
    $detectedMime,
    json_encode(array_keys($seenPartNumbers)),
    $uploadId,
]);

upload_endpoint_succeed([
    'uploadId' => $uploadId,
    'objectKey' => $result['object_key'],
    'url' => $result['location'],
    'etag' => $result['etag'],
    'storedFilename' => $session['stored_filename'],
    'originalFilename' => $session['original_filename'],
    'size' => $storedSize,
    'mimeType' => $detectedMime,
]);

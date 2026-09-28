<?php
/**
 * AJAX Handler: Get Upload Status / Resume
 * Returns completed chunks and remaining presigned URLs for resumable uploads.
 *
 * Remaining URLs are tagged with their part number. The client used to
 * re-derive part numbers positionally, which silently mis-assigned ETags to
 * parts as soon as completion tracking actually worked.
 */
require_once __DIR__ . '/upload_endpoint.php';

upload_endpoint_boot('GET');
upload_endpoint_require_csrf();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = (string) ($_SESSION['user_role'] ?? '');
$uploadId = trim((string) ($_GET['uploadId'] ?? ''));

if ($uploadId === '') {
    upload_endpoint_fail(422, 'Upload ID required');
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
    upload_endpoint_fail(404, 'Upload session not found or expired');
}

if (strtotime((string) $session['expires_at']) < time()) {
    $conn->prepare("UPDATE upload_sessions SET status = 'expired' WHERE upload_id = ?")->execute([$uploadId]);
    upload_endpoint_fail(410, 'Upload session expired');
}

$allPresignedUrls = json_decode((string) ($session['presigned_urls'] ?? '[]'), true);
if (!is_array($allPresignedUrls)) {
    $allPresignedUrls = [];
}

$completedChunks = upload_endpoint_completed_chunks($session['completed_chunks'] ?? null);
$completedLookup = array_flip($completedChunks);
$remaining = [];

foreach (array_values($allPresignedUrls) as $index => $url) {
    $partNumber = $index + 1;
    if (!isset($completedLookup[$partNumber])) {
        $remaining[] = ['partNumber' => $partNumber, 'url' => (string) $url];
    }
}

$totalChunks = (int) $session['total_chunks'];
$allPartsComplete = count($completedChunks) === $totalChunks;

upload_endpoint_succeed([
    'uploadId' => $session['upload_id'],
    's3UploadId' => $session['s3_upload_id'],
    'objectKey' => $session['object_key'],
    'chunkSize' => (int) $session['chunk_size'],
    'totalChunks' => $totalChunks,
    'completedChunks' => $completedChunks,
    'remainingPresignedUrls' => $remaining,
    'allPartsComplete' => $allPartsComplete,
    'expiresAt' => $session['expires_at'],
    'status' => $session['status'],
    'originalFilename' => $session['original_filename'],
]);

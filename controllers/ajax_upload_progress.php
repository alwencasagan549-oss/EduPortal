<?php
/**
 * AJAX Handler: Record Uploaded Parts
 *
 * Persists which parts have landed so an interrupted upload can resume.
 * Without this, `completed_chunks` was never written and the status endpoint
 * could only ever report zero completed parts.
 */
require_once __DIR__ . '/upload_endpoint.php';

upload_endpoint_boot('POST');
upload_endpoint_require_csrf();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = (string) ($_SESSION['user_role'] ?? '');
$uploadId = trim((string) ($_POST['uploadId'] ?? ''));
$chunksRaw = $_POST['chunks'] ?? '[]';

if ($uploadId === '') {
    upload_endpoint_fail(422, 'Upload ID required');
}

$chunks = is_string($chunksRaw) ? json_decode($chunksRaw, true) : $chunksRaw;
if (!is_array($chunks) || $chunks === []) {
    upload_endpoint_fail(422, 'No parts reported');
}

$conn = getDBConnection();
$stmt = $conn->prepare(
    "SELECT * FROM upload_sessions
     WHERE upload_id = ? AND user_id = ? AND user_role = ?
       AND status NOT IN ('completed', 'cancelled', 'aborted', 'expired')"
);
$stmt->execute([$uploadId, $userId, $userRole]);
$session = $stmt->fetch_assoc();

if (!$session) {
    upload_endpoint_fail(404, 'Upload session not found or expired');
}
if (strtotime((string) $session['expires_at']) < time()) {
    upload_endpoint_fail(410, 'Upload session expired');
}

$totalChunks = (int) $session['total_chunks'];

// Accepts bare part numbers or [{partNumber, etag}] objects.
$reported = upload_endpoint_completed_chunks($chunks);

// Accept only part numbers inside this session's range.
$completed = upload_endpoint_completed_chunks($session['completed_chunks'] ?? null);
$merged = array_merge($completed, $reported);
foreach ($merged as $partNumber) {
    if ($partNumber > $totalChunks) {
        upload_endpoint_fail(422, 'Reported part number is outside this upload');
    }
}

$conn->prepare(
    'UPDATE upload_sessions SET completed_chunks = ?, updated_at = CURRENT_TIMESTAMP WHERE upload_id = ?'
)->execute([json_encode($merged), $uploadId]);

upload_endpoint_succeed([
    'uploadId' => $uploadId,
    'completedChunks' => $merged,
    'totalChunks' => $totalChunks,
]);

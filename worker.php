<?php
/**
 * Cleanup worker: reaps abandoned chunked uploads.
 *
 * Every `initiate` opens a real multipart upload in R2/S3. Sessions that are
 * never completed leave those parts billable forever, because nothing aborted
 * them. Run periodically:
 *
 *   php worker.php cleanup_uploads
 *   php worker.php cleanup_notifications
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/ObjectStorageService.php';

function cleanup_expired_uploads(int $batchSize = 200): int
{
    $conn = getDBConnection();
    $cutoff = gmdate('Y-m-d H:i:s', time() - 3600);

    $stmt = $conn->prepare(
        "SELECT upload_id, object_key, s3_upload_id
         FROM upload_sessions
         WHERE expires_at < ? AND status NOT IN ('completed', 'cancelled', 'aborted')
         ORDER BY expires_at ASC
         LIMIT ?"
    );
    $stmt->execute([gmdate('Y-m-d H:i:s', time()), $batchSize]);
    $sessions = $stmt->fetch_all();

    if ($sessions === []) {
        return 0;
    }

    $storage = null;
    try {
        $storage = \EduPortal\ObjectStorageService::fromEnv();
    } catch (Throwable $exception) {
        error_log('EduPortal upload cleanup skipped (storage unconfigured): ' . $exception->getMessage());
    }

    $reaped = 0;
    foreach ($sessions as $session) {
        $objectKey = (string) ($session['object_key'] ?? '');
        $s3UploadId = (string) ($session['s3_upload_id'] ?? '');

        if ($storage !== null && $objectKey !== '' && $s3UploadId !== '') {
            $storage->abortMultipartUpload($objectKey, $s3UploadId);
        }

        $conn->prepare(
            "UPDATE upload_sessions SET status = 'expired', updated_at = CURRENT_TIMESTAMP WHERE upload_id = ?"
        )->execute([$session['upload_id']]);
        $reaped++;
    }

    echo "Upload cleanup: reaped {$reaped} expired session(s).\n";
    return $reaped;
}

function cleanup_old_notifications(int $retentionDays = 180): int
{
    $conn = getDBConnection();
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));
    $stmt = $conn->prepare('DELETE FROM notifications WHERE created_at < ?');
    $stmt->execute([$cutoff]);
    $deleted = $stmt->rowCount();

    echo "Notification cleanup: removed {$deleted} notification(s) older than {$retentionDays} days.\n";
    return (int) $deleted;
}

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $task = $argv[1] ?? 'cleanup_uploads';

    switch ($task) {
        case 'cleanup_uploads':
            cleanup_expired_uploads();
            break;
        case 'cleanup_notifications':
            cleanup_old_notifications();
            break;
        case 'cleanup_all':
            cleanup_expired_uploads();
            cleanup_old_notifications();
            break;
        default:
            fwrite(STDERR, "Unknown task '{$task}'. Use cleanup_uploads, cleanup_notifications or cleanup_all.\n");
            exit(1);
    }
}

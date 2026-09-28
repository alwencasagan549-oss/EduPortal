<?php
/**
 * Shared bootstrap for the chunked-upload AJAX endpoints.
 *
 * Centralises the auth, method, CSRF and JSON-response contract so every
 * endpoint returns a real HTTP status and a machine-readable body. A client
 * that only checks `response.ok` cannot silently treat a 403 as success.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/assignment_management.php';

function upload_endpoint_boot(string $method): void
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    if (!isLoggedIn()) {
        upload_endpoint_fail(401, 'Your session has expired. Sign in again to continue.');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        upload_endpoint_fail(405, 'Method not allowed');
    }
}

function upload_endpoint_token()
{
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $token = is_string($header) && $header !== '' ? $header : ($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    return is_string($token) ? $token : '';
}

function upload_endpoint_require_csrf(): void
{
    if (!validate_csrf(upload_endpoint_token())) {
        upload_endpoint_fail(419, 'Invalid security token');
    }
}

/** Terminates the request with a JSON error body and a real status code. */
function upload_endpoint_fail(int $status, string $error): void
{
    if (!headers_sent()) {
        http_response_code($status);
    }
    echo json_encode(['success' => false, 'error' => $error]);
    exit();
}

function upload_endpoint_succeed(array $payload): void
{
    echo json_encode(array_merge(['success' => true], $payload));
    exit();
}

function upload_endpoint_object_storage(): \EduPortal\ObjectStorageService
{
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoloadPath) || !class_exists(\Aws\S3\S3Client::class)) {
        upload_endpoint_fail(
            503,
            'Object storage is not available on this server. Run "composer install".'
        );
    }

    require_once __DIR__ . '/../src/ObjectStorageService.php';

    try {
        return \EduPortal\ObjectStorageService::fromEnv();
    } catch (\RuntimeException $exception) {
        error_log('EduPortal object storage configuration error: ' . $exception->getMessage());
        upload_endpoint_fail(503, 'Object storage is not configured on this server.');
    }
}

/**
 * Normalises the persisted completed-part list.
 *
 * The column has been declared as INTEGER[] and as TEXT in different
 * deployments, and PDO returns a Postgres array as the string "{1,2,3}".
 * Everything funnels through here so callers always receive a sorted list of
 * positive integers. Entries may be bare values or {partNumber, etag} objects.
 */
function upload_endpoint_completed_chunks($raw): array
{
    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }
        $raw = json_decode($trimmed, true);
        if ($raw === null) {
            // Postgres array literal, e.g. "{1,2,3}"
            $inner = trim($trimmed, '{}');
            $raw = $inner === '' ? [] : explode(',', $inner);
        }
    }

    if (!is_array($raw)) {
        return [];
    }

    $normalised = [];
    foreach ($raw as $entry) {
        if (is_array($entry)) {
            $entry = $entry['partNumber'] ?? $entry['part_number'] ?? null;
        }
        if (is_string($entry)) {
            $entry = trim($entry, " \t\n\r\0\x0B\"'");
        }
        if (is_numeric($entry) && (int) $entry > 0) {
            $normalised[(int) $entry] = (int) $entry;
        }
    }

    ksort($normalised);
    return array_values($normalised);
}

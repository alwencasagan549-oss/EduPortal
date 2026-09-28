<?php
/**
 * AJAX: WebAuthn authentication options.
 *
 * Unauthenticated by design: the user has only typed their identifier, which
 * proves nothing yet. Resolves the account from the submitted identifier and
 * returns the request ceremony options scoped to that account's passkeys.
 *
 * The failure message is identical whether the account is missing, disabled or
 * simply has no passkey, so this cannot be used to enumerate accounts.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/AuthService.php';
require_once __DIR__ . '/../libs/WebAuthnService.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

if (!validate_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token.', 'csrf_token' => csrf_token()]);
    exit();
}
rotate_csrf();

$conn = getDBConnection();

if (!webauthn_available()) {
    http_response_code(503);
    echo json_encode(['error' => 'Passkey sign-in is not available on this server.']);
    exit();
}

$role = ((string) ($_POST['role'] ?? 'student')) === 'teacher' ? 'teacher' : 'student';
$identifier = trim((string) ($_POST['identifier'] ?? ''));
$subject = trim((string) ($_POST['subject'] ?? ''));

if ($identifier === '' || ($role === 'teacher' && $subject === '')) {
    http_response_code(422);
    echo json_encode(['error' => 'Enter your details first.', 'csrf_token' => csrf_token()]);
    exit();
}

$genericError = 'Passkey sign-in is not available for this account. Use your password instead.';

// Unauthenticated database-touching endpoint, so it gets the same persistent
// throttling as a password guess.
$buckets = [
    auth_rate_limit_bucket_key('ip', auth_client_ip()),
    auth_rate_limit_bucket_key($role, strtolower($identifier . '|' . $subject)),
];

$lockedFor = auth_rate_limit_check($conn, $buckets);
if ($lockedFor !== null && $lockedFor > 0) {
    http_response_code(429);
    header('Retry-After: ' . $lockedFor);
    echo json_encode(['error' => 'Too many attempts. Please wait ' . $lockedFor . ' seconds.', 'csrf_token' => csrf_token()]);
    exit();
}

$account = $role === 'student'
    ? auth_find_student($conn, $identifier)
    : auth_find_teacher($conn, $identifier, $subject);

$started = $account === null ? null : webauthn_begin_authentication($conn, $role, (int) $account['id']);

if ($started === null) {
    auth_rate_limit_failure($conn, $buckets);
    auth_record_event($conn, 'login', 'passkey_unavailable', [
        'user_role' => $role,
        'identifier' => $identifier,
    ]);
    http_response_code(404);
    echo json_encode(['error' => $genericError, 'csrf_token' => csrf_token()]);
    exit();
}

echo json_encode(['publicKey' => json_decode($started['options'], true), 'csrf_token' => csrf_token()]);

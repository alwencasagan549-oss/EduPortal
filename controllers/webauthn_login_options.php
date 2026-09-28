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

$genericError = 'No matching passkey was found on this device. Use your password instead.';

// The identifier is optional. With it, the options are scoped to that
// account's credentials. Without it, the authenticator resolves the account
// from the discoverable credential it already holds, so the user can sign in
// with a passkey alone.
$wantsScoped = $identifier !== '' && ($role === 'student' || $subject !== '');

// Unauthenticated endpoint that mints a challenge on every call, so it stays
// throttled. The IP bucket is always present because the discoverable path has
// no account to key on; it is deliberately loose, since a campus shares one
// NAT address and a tight limit would lock out a whole class.
$buckets = [auth_rate_limit_bucket_key('ip', auth_client_ip())];
if ($wantsScoped) {
    $buckets[] = auth_rate_limit_bucket_key($role, strtolower($identifier . '|' . $subject));
}

$lockedFor = auth_rate_limit_check($conn, $buckets);
if ($lockedFor !== null && $lockedFor > 0) {
    http_response_code(429);
    header('Retry-After: ' . $lockedFor);
    echo json_encode(['error' => 'Too many attempts. Please wait ' . $lockedFor . ' seconds.', 'csrf_token' => csrf_token()]);
    exit();
}

$account = null;
if ($wantsScoped) {
    $account = $role === 'student'
        ? auth_find_student($conn, $identifier)
        : auth_find_teacher($conn, $identifier, $subject);
}

$started = webauthn_begin_authentication($conn, $role, $wantsScoped ? $account : null);

if ($started === null) {
    auth_rate_limit_failure($conn, $buckets);
    auth_record_event($conn, 'login', 'passkey_unavailable', [
        'user_role' => $role,
        'identifier' => $wantsScoped ? $identifier : null,
        'detail' => webauthn_last_error(),
    ]);
    // Identical whether the account is unknown, has no passkey, or the caller
    // simply supplied nothing, so this cannot be used to probe for accounts.
    http_response_code(404);
    echo json_encode(['error' => $genericError, 'csrf_token' => csrf_token()]);
    exit();
}

echo json_encode([
    'publicKey' => json_decode($started['options'], true),
    'csrf_token' => csrf_token(),
    'scoped' => $started['scoped'],
]);

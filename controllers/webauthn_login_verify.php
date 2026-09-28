<?php
/**
 * AJAX: WebAuthn authentication verification.
 *
 * Consumes the assertion and establishes a session. The session lifecycle is
 * identical to a password sign-in: ID regeneration, fingerprint binding, and
 * the activity stamps the idle timeout reads.
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

if (isLoggedIn()) {
    // Refusing here is deliberate: silently swapping the account under an
    // existing session is exactly the surprise a passkey must not cause. The
    // message has to say so and name the account, because "you are already
    // signed in" reads as a failure rather than as "sign out first".
    $currentRole = getUserRole();
    $currentName = (string) ($_SESSION['user_name'] ?? '');
    $currentDetail = $currentRole === 'teacher'
        ? (string) ($_SESSION['user_email'] ?? '')
        : 'LRN ' . (string) ($_SESSION['user_lrn'] ?? '');
    $current = trim($currentName . ' (' . $currentDetail . ')');

    auth_record_event(getDBConnection(), 'login', 'passkey_while_signed_in', [
        'user_role' => $currentRole,
        'user_id' => $_SESSION['user_id'] ?? null,
    ]);

    http_response_code(409);
    echo json_encode([
        'error' => 'You are already signed in as ' . $current . '. '
            . 'Sign out first if you want to use a different account\'s passkey.',
        'csrf_token' => csrf_token(),
    ]);
    exit();
}

$credentialJson = (string) ($_POST['credential'] ?? '');

if (strlen($credentialJson) > 16384) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid passkey response.']);
    exit();
}

$role_hint = ((string) ($_POST['role'] ?? 'student')) === 'teacher' ? 'teacher' : 'student';
$identifier = trim((string) ($_POST['identifier'] ?? ''));

// Both roles identify themselves before a credential is accepted. Re-checked
// here so the endpoint is safe on its own, in case the options call is
// bypassed; the ceremony is already scoped to the account those fields name,
// so a credential belonging to anyone else fails verification regardless.
if (($role_hint === 'teacher' && trim((string) ($_POST['subject'] ?? '')) === '')
    || ($role_hint === 'student' && $identifier === '')) {
    http_response_code(422);
    echo json_encode([
        'error' => $role_hint === 'teacher'
            ? 'Enter the subject you are signing in as, then use your passkey.'
            : 'Enter your LRN, then use your passkey.',
        'csrf_token' => csrf_token(),
    ]);
    exit();
}

$result = webauthn_finish_authentication(
    $conn,
    $credentialJson,
    (string) ($_POST['subject'] ?? '')
);

if (!$result['ok']) {
    auth_record_event($conn, 'login', 'passkey_failure', [
        'user_role' => $result['user_role'] !== '' ? $result['user_role'] : null,
        'user_id' => $result['user_id'] > 0 ? $result['user_id'] : null,
    ]);
    http_response_code(401);
    echo json_encode(['error' => $result['error'], 'csrf_token' => csrf_token()]);
    exit();
}

$role = $result['user_role'];
$account = $result['account'];

// A successful passkey sign-in clears the account's strike counter exactly as
// a password sign-in does. auth_account_login_buckets() rebuilds the same key
// the login path would have written, so this is not a silent no-op.
auth_rate_limit_clear($conn, auth_account_login_buckets($role, $account));

auth_establish_session($account, $role);

auth_record_event($conn, 'login', 'passkey_success', [
    'user_role' => $role,
    'user_id' => $result['user_id'],
]);

echo json_encode([
    'ok' => true,
    'csrf_token' => csrf_token(),
    // Root-absolute, not relative. The caller is /teacher/login.php or
    // /student/login.php, so a relative "teacher/dashboard.php" resolved
    // against the login page's own directory and produced
    // /teacher/teacher/dashboard.php. This matches the root-absolute form
    // requireLogin() already uses.
    'redirect' => $role === 'teacher' ? '/teacher/dashboard.php' : '/student/dashboard.php',
]);

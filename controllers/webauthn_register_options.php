<?php
/**
 * AJAX: WebAuthn registration options.
 *
 * Issues the creation ceremony options for the signed-in account.
 *
 * Step-up: enrolling a passkey adds a new way to sign in, so it requires the
 * current password even though the session is already authenticated. A
 * hijacked session must not be enough to mint a durable credential.
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

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!validate_csrf($_POST['csrf_token'] ?? '')) {
    auth_record_event(getDBConnection(), 'passkey_register', 'csrf_failure', [
        'user_role' => getUserRole(),
        'user_id' => $_SESSION['user_id'] ?? null,
    ]);
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token.', 'csrf_token' => csrf_token()]);
    exit();
}
rotate_csrf();

$conn = getDBConnection();
$role = getUserRole();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if (!webauthn_available()) {
    http_response_code(503);
    echo json_encode(['error' => 'Passkey sign-in is not available on this server.']);
    exit();
}

// ---------------------------------------------------------------------
// Step-up verification
// ---------------------------------------------------------------------

$table = auth_role_table($role);
$password = (string) ($_POST['password'] ?? '');

$verified = false;
if ($table !== null) {
    try {
        $stmt = $conn->prepare("SELECT password FROM {$table} WHERE id = ?");
        $stmt->execute([$userId]);
        $stored = $stmt->get_result()->fetchColumn();
        $verified = is_string($stored) && $stored !== '' && password_verify($password, $stored);
    } catch (Throwable $exception) {
        error_log('EduPortal passkey step-up lookup failed: ' . $exception->getMessage());
    }
}

if (!$verified) {
    auth_record_event($conn, 'passkey_register', 'step_up_failure', [
        'user_role' => $role,
        'user_id' => $userId,
    ]);
    http_response_code(403);
    echo json_encode(['error' => 'Password confirmation failed. Enter your current password to add a passkey.', 'csrf_token' => csrf_token()]);
    exit();
}

$account = auth_account_for_role($conn, $role, $userId);
if ($account === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Account not found.']);
    exit();
}

$started = webauthn_begin_registration($conn, $role, $userId, $account);

if ($started === null) {
    auth_record_event($conn, 'passkey_register', 'options_failure', [
        'user_role' => $role,
        'user_id' => $userId,
        // Which early return was hit, so this is diagnosable with a query
        // rather than only from a host log.
        'detail' => webauthn_last_error(),
    ]);
    http_response_code(503);
    echo json_encode(['error' => 'Passkeys could not be started. Please try again later.']);
    exit();
}

auth_record_event($conn, 'passkey_register', 'options_issued', [
    'user_role' => $role,
    'user_id' => $userId,
]);

echo json_encode([
    'publicKey' => json_decode($started['options'], true),
    'csrf_token' => csrf_token(),
]);

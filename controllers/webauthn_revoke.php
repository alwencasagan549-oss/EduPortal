<?php
/**
 * AJAX: WebAuthn passkey revocation.
 *
 * Requires the same step-up password confirmation as enrolment. Revocation is
 * a destructive security operation: without step-up, anyone holding a hijacked
 * session could strip a user's passkeys and push them back to (or into) the
 * password reset flow.
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
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token.', 'csrf_token' => csrf_token()]);
    exit();
}
rotate_csrf();

$conn = getDBConnection();
$role = getUserRole();
$userId = (int) ($_SESSION['user_id'] ?? 0);
$passkeyId = (int) ($_POST['passkey_id'] ?? 0);
$password = (string) ($_POST['password'] ?? '');

$table = auth_role_table($role);
$verified = false;

if ($table !== null) {
    try {
        $stmt = $conn->prepare("SELECT password FROM {$table} WHERE id = ?");
        $stmt->execute([$userId]);
        $stored = $stmt->get_result()->fetchColumn();
        $verified = is_string($stored) && $stored !== '' && password_verify($password, $stored);
    } catch (Throwable $exception) {
        error_log('EduPortal passkey revoke step-up failed: ' . $exception->getMessage());
    }
}

if (!$verified) {
    auth_record_event($conn, 'passkey_revoke', 'step_up_failure', [
        'user_role' => $role,
        'user_id' => $userId,
    ]);
    http_response_code(403);
    echo json_encode(['error' => 'Password confirmation failed.', 'csrf_token' => csrf_token()]);
    exit();
}

$result = webauthn_revoke_passkey($conn, $role, $userId, $passkeyId);

if (!$result['ok']) {
    auth_record_event($conn, 'passkey_revoke', 'failure', [
        'user_role' => $role,
        'user_id' => $userId,
        'detail' => 'passkey_id=' . $passkeyId,
    ]);
    http_response_code(404);
    echo json_encode(['error' => $result['error'], 'csrf_token' => csrf_token()]);
    exit();
}

auth_record_event($conn, 'passkey_revoke', 'success', [
    'user_role' => $role,
    'user_id' => $userId,
    'detail' => 'passkey_id=' . $passkeyId,
]);

echo json_encode([
    'ok' => true,
    'csrf_token' => csrf_token(),
    'remaining' => $result['remaining'],
    'passkeys' => webauthn_list_passkeys($conn, $role, $userId),
]);

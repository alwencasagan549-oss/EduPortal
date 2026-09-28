<?php
/**
 * AJAX: WebAuthn passkey rename.
 *
 * Purely cosmetic, so it does not demand the step-up password that enrolment
 * and revocation do. It still requires an authenticated session and a CSRF
 * token, and the account predicate lives in the UPDATE.
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

$result = webauthn_rename_passkey(
    $conn,
    $role,
    $userId,
    (int) ($_POST['passkey_id'] ?? 0),
    (string) ($_POST['label'] ?? '')
);

if (!$result['ok']) {
    http_response_code(400);
    echo json_encode(['error' => $result['error'], 'csrf_token' => csrf_token()]);
    exit();
}

echo json_encode([
    'ok' => true,
    'csrf_token' => csrf_token(),
    'passkeys' => webauthn_list_passkeys($conn, $role, $userId),
]);

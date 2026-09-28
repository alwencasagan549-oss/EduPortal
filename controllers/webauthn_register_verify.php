<?php
/**
 * AJAX: WebAuthn registration verification.
 *
 * Consumes the attestation, stores the credential, and records the event.
 * Requires an authenticated session; the step-up password check already
 * happened when the options were issued.
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

$credentialJson = (string) ($_POST['credential'] ?? '');
$label = (string) ($_POST['label'] ?? '');

if (strlen($credentialJson) > 16384) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid passkey response.']);
    exit();
}

$result = webauthn_finish_registration($conn, $role, $userId, $credentialJson, $label);

if (!$result['ok']) {
    auth_record_event($conn, 'passkey_register', 'failure', [
        'user_role' => $role,
        'user_id' => $userId,
    ]);
    http_response_code(400);
    echo json_encode(['error' => $result['error'], 'csrf_token' => csrf_token()]);
    exit();
}

auth_record_event($conn, 'passkey_register', 'success', [
    'user_role' => $role,
    'user_id' => $userId,
    'detail' => 'passkey_id=' . $result['passkey_id'],
]);

echo json_encode([
    'ok' => true,
    'csrf_token' => csrf_token(),
    'passkeys' => webauthn_list_passkeys($conn, $role, $userId),
]);

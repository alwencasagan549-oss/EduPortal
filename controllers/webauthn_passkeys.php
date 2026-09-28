<?php
/**
 * AJAX: WebAuthn passkey list.
 *
 * Read-only, authenticated. Returns display metadata only -- never the
 * credential id, public key, or user handle, which are not the profile
 * page's business.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/AuthService.php';
require_once __DIR__ . '/../libs/WebAuthnService.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$conn = getDBConnection();
$role = getUserRole();
$userId = (int) ($_SESSION['user_id'] ?? 0);

echo json_encode([
    'ok' => true,
    'available' => webauthn_available(),
    'passkeys' => webauthn_list_passkeys($conn, $role, $userId),
]);

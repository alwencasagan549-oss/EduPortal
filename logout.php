<?php
// logout.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/libs/AuthService.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validate_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: index.php');
    exit();
}

// Recorded before the session is torn down: afterwards the user identity and
// client fingerprint are no longer available to attribute the event.
try {
    auth_logout_audit(getDBConnection());
} catch (Throwable $exception) {
    error_log('EduPortal logout audit failed: ' . $exception->getMessage());
}

// Unset all session variables
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax'
    ]);
}

// Destroy the session
session_destroy();

// Clear buffer and redirect
if (ob_get_level()) {
    ob_end_clean();
}
header('Location: index.php');
exit();
?>
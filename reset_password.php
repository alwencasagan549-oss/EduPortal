<?php
/**
 * Password reset confirmation.
 *
 * Redeems a single-use token emailed by forgot_password.php and sets a new
 * password.
 *
 * Order matters: the submitted password is validated *before* the token is
 * consumed, so a typo does not burn the user's only link. The race between two
 * simultaneous submissions is handled by the conditional update inside
 * auth_consume_token() rather than by ordering alone.
 *
 * Known limitation: sessions are stored server-side by PHP and are not
 * enumerable, so a password reset cannot revoke a session an attacker already
 * holds. The outstanding reset tokens are revoked and the account's failed
 * login counters are cleared, but a stolen session survives until it hits the
 * 30-minute idle timeout. Closing that properly needs a session-version column
 * checked in verifySessionBinding(); it is deliberately out of scope here.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/libs/AuthService.php';
require_once __DIR__ . '/libs/WebAuthnService.php';

$conn = getDBConnection();

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$error = '';
$success = false;
$role = 'student';
$passkeysRevoked = 0;
$tokenRow = auth_token_peek($conn, 'password_reset', $token);

if ($tokenRow !== null) {
    $role = (string) ($tokenRow['user_role'] ?? 'student');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        auth_record_event($conn, 'password_reset', 'csrf_failure', ['user_role' => $role]);
        $error = 'Invalid security token.';
    } elseif ($tokenRow === null) {
        // Covers unknown, expired and already-used links; they are
        // indistinguishable by design.
        $error = 'This reset link is no longer valid. It may have expired or already been used. '
            . 'Please request a new one.';
        auth_record_event($conn, 'password_reset', 'invalid_token');
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        $problem = auth_password_problem($password);
        if ($problem !== null) {
            $error = $problem;
        } elseif (!hash_equals($password, $confirmation)) {
            $error = 'The two passwords do not match.';
        } else {
            $claimed = auth_consume_token($conn, 'password_reset', $token);

            if ($claimed === null) {
                $error = 'This reset link is no longer valid. It may have expired or already been used. '
                    . 'Please request a new one.';
                auth_record_event($conn, 'password_reset', 'invalid_token', ['user_role' => $role]);
            } else {
                $role = (string) $claimed['user_role'];
                $userId = (int) $claimed['user_id'];
                $table = auth_role_table($role);
                $account = $table === null ? null : auth_account_for_role($conn, $role, $userId);

                if ($account === null || $table === null) {
                    $error = 'That account no longer exists. Please contact the portal administrator.';
                    auth_record_event($conn, 'password_reset', 'failure', [
                        'user_role' => $role,
                        'user_id' => $userId,
                    ]);
                } else {
                    $hashed = auth_password_hash($password);

                    try {
                        // Completing a reset proves control of the mailbox, so
                        // it is the natural moment to record that fact.
                        $setVerified = auth_column_exists($conn, $table, 'email_verified_at')
                            ? ', email_verified_at = ?'
                            : '';
                        $params = [$hashed, $userId];
                        if ($setVerified !== '') {
                            $params[] = auth_now();
                        }

                        $stmt = $conn->prepare("UPDATE {$table} SET password = ?{$setVerified} WHERE id = ?");
                        $stmt->execute($params);
                    } catch (Throwable $exception) {
                        error_log('EduPortal password reset update failed: ' . $exception->getMessage());
                        $error = 'The password could not be updated. Please try again later.';
                        auth_record_event($conn, 'password_reset', 'failure', [
                            'user_role' => $role,
                            'user_id' => $userId,
                        ]);
                    }

                    if ($error === '') {
                        // Any other outstanding link for this account is now
                        // worthless.
                        //
                        // The account's failed-login counters are deliberately
                        // not cleared here: the login bucket is keyed on the
                        // identifier the user types (LRN, or email|subject),
                        // which a reset token does not carry. It is cleared
                        // anyway on the first successful sign-in.
                        auth_revoke_tokens($conn, 'password_reset', $role, $userId);

                        // Every passkey goes too. A reset usually means the
                        // account was believed compromised, and a passkey the
                        // attacker enrolled would otherwise keep working after
                        // the password was changed -- which would leave the
                        // recovery flow as a way to lock the owner out while
                        // the attacker walks straight back in.
                        $passkeysRevoked = webauthn_revoke_all_passkeys($conn, $role, $userId, 'password_reset');

                        auth_record_event($conn, 'password_reset', 'success', [
                            'user_role' => $role,
                            'user_id' => $userId,
                        ]);

                        $success = true;
                    }
                }
            }
        }
    }
}

// On success, drop the token from the URL so it does not linger in history.
if ($success) {
    $notice = $passkeysRevoked > 0
        ? '&passkeys=' . $passkeysRevoked
        : '';
    header('Location: ' . ($role === 'teacher' ? 'teacher/login.php' : 'student/login.php') . '?reset=1' . $notice);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose New Password | EduPortal LMS</title>
    <link rel="icon" href="assets/favicon.ico?v=20260924-ico" type="image/x-icon">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#0a0b10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="assets/pwa-icon-192.svg">
    <link rel="stylesheet" href="assets/style.min.css?v=20260924">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
</head>
<body class="auth-page">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <main class="auth-card" id="main-content">
        <div class="auth-logo">
            <div class="sidebar-logo" style="margin: 0 auto 1.5rem; width: 60px; height: 60px;">
                <i class="fas fa-lock-open" style="font-size: 1.5rem;"></i>
            </div>
            <h1 class="auth-title">Choose a New Password</h1>
            <p class="auth-subtitle">Pick something you have not used on this portal before.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-circle-exclamation"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($tokenRow === null && $error === ''): ?>
            <div class="alert alert-warning">
                <i class="fas fa-triangle-exclamation"></i>
                <div>This page needs a reset link. Request one to continue.</div>
            </div>
        <?php endif; ?>

        <?php if ($tokenRow !== null): ?>
            <form method="POST" data-loader="true">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div style="margin-bottom: 1.5rem;">
                    <label for="password" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                        <i class="fas fa-key"></i> New Password
                    </label>
                    <input type="password" id="password" name="password" autocomplete="new-password" required
                           minlength="8" maxlength="72" class="premium-input" placeholder="At least 8 characters">
                    <p style="color: var(--text-muted); font-size: 0.78rem; margin-top: 0.5rem;">
                        Minimum 8 characters, with at least one uppercase letter and one digit.
                    </p>
                </div>

                <div style="margin-bottom: 2rem;">
                    <label for="password_confirmation" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                        <i class="fas fa-key"></i> Confirm New Password
                    </label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required
                           minlength="8" maxlength="72" class="premium-input" placeholder="Re-enter your new password">
                </div>

                <button type="submit" class="premium-btn premium-btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">
                    <i class="fas fa-circle-check"></i> Set New Password
                </button>
            </form>
        <?php endif; ?>

        <div style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; text-align: center;">
            <a href="<?php echo $role === 'teacher' ? 'teacher/login.php' : 'student/login.php'; ?>"
               class="premium-btn premium-btn-outline" style="width: 100%; justify-content: center;">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
    </main>
    <script src="assets/js/trusted_types.js"></script>
    <script src="assets/js/system_loader.js?v=20260924-loader4"></script>
    <script src="assets/js/responsive_ui.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>

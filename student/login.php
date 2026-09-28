<?php
require_once '../config/database.php';
require_once '../libs/AuthService.php';
require_once '../libs/WebAuthnService.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDBConnection();

    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        // A CSRF failure on the login form is a signal in its own right, not
        // just a form error, so it is recorded rather than silently dropped.
        auth_record_event($conn, 'login', 'csrf_failure', ['user_role' => 'student']);
        $error = 'Invalid security token.';
    } else {
        $result = auth_attempt_password_login($conn, 'student', [
            'identifier' => $_POST['lrn'] ?? '',
            'password' => $_POST['password'] ?? '',
        ]);

        if ($result['ok']) {
            auth_establish_session($result['account'], 'student');
            header('Location: dashboard.php');
            exit();
        }

        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login | EduPortal LMS</title>
    <link rel="icon" href="../assets/favicon.ico?v=20260924-ico" type="image/x-icon">
    <link rel="manifest" href="../manifest.webmanifest">
    <meta name="theme-color" content="#0a0b10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="../assets/pwa-icon-192.svg">
    <link rel="stylesheet" href="../assets/style.min.css?v=20260924">
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
                <i class="fas fa-graduation-cap" style="font-size: 1.5rem;"></i>
            </div>
            <h1 class="auth-title">Student Hub</h1>
            <p class="auth-subtitle">Welcome to your learning journey!</p>
        </div>

        <?php if (isset($_GET['reset']) && $_GET['reset'] === '1'): ?>
            <div class="alert alert-success">
                <i class="fas fa-circle-check"></i>
                <div>Your password has been updated. Sign in with your new password.</div>
            </div>
            <?php if (isset($_GET['passkeys']) && (int) $_GET['passkeys'] > 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-triangle-exclamation"></i>
                    <div>
                        For your security, <?php echo (int) $_GET['passkeys']; ?>
                        passkey<?php echo (int) $_GET['passkeys'] === 1 ? ' was' : 's were'; ?> removed.
                        Sign in and add a passkey again from your profile page.
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-triangle-exclamation"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" data-loader="true">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div style="margin-bottom: 1.5rem;">
                <label for="lrn" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-hashtag"></i> Learner Reference Number (LRN)
                </label>
                <input type="text" id="lrn" name="lrn" autocomplete="username" required class="premium-input"
                       placeholder="Enter your 12-digit LRN" maxlength="12" minlength="12" 
                       pattern="\d{12}" inputmode="numeric"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '')">
            </div>
            
            <div style="margin-bottom: 2rem;">
                <label for="password" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-key"></i> Password
                </label>
                <input type="password" id="password" name="password" autocomplete="current-password" required class="premium-input" placeholder="••••••••">
            </div>
            
            <button type="submit" class="premium-btn premium-btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">
                <i class="fas fa-right-to-bracket"></i> Login to Portal
            </button>

            <p style="text-align: center; margin-top: 1rem;">
                <a href="../forgot_password.php?role=student" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none;">
                    <i class="fas fa-key"></i> Forgot your password?
                </a>
            </p>
        </form>

        <div id="passkey-login" hidden style="margin-top: 1.25rem; text-align: center;">
            <button type="button" id="passkey-login-button" class="premium-btn premium-btn-outline" style="width: 100%; justify-content: center;">
                <i class="fas fa-fingerprint"></i> Sign in with a passkey
            </button>
            <p id="passkey-login-status" role="status" aria-live="polite" style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.75rem;"></p>
        </div>

        <div style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; text-align: center;">
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">Don't have an account yet?</p>
            <a href="signup.php" class="premium-btn premium-btn-outline" style="width: 100%; justify-content: center;">
                <i class="fas fa-user-plus"></i> Create Student Account
            </a>
            <div style="margin-top: 2.5rem; border-top: 1px solid var(--glass-border); padding-top: 1rem; text-align: center; color: var(--text-muted); font-size: 0.8rem;">
                <p>&copy; 2026 EduPortal. Web Developer: <strong id="_sys_v_auth"><a href="https://casagan.vercel.app/" target="_blank" style="color: inherit; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwin T. Casagan</a></strong></p>
                <a href="../index.php" style="color: var(--text-muted); text-decoration: none; display: inline-block; margin-top: 10px; transition: color 0.2s;" onmouseover="this.style.color='var(--text-main)'" onmouseout="this.style.color='var(--text-muted)'">
                    <i class="fas fa-arrow-left"></i> Home Page
                </a>
            </div>
        </div>
    </main>
    <script src="../assets/js/trusted_types.js"></script>
    <script src="../assets/js/system_loader.js?v=20260924-loader4"></script>
    <script src="../assets/js/responsive_ui.js"></script>
    <script src="../assets/js/webauthn.js?v=<?php echo WEBAUTHN_JS_VERSION; ?>"></script>
    <script src="../assets/js/pwa.js"></script>
    <script>
    (() => {
        const wrapper = document.getElementById('passkey-login');
        const button = document.getElementById('passkey-login-button');
        const status = document.getElementById('passkey-login-status');
        const lrn = document.getElementById('lrn');

        if (!wrapper || typeof window.EduPortalWebAuthn === 'undefined' || !window.EduPortalWebAuthn.isSupported()) {
            return;
        }

        const api = window.EduPortalWebAuthn;
        api.init(<?php echo json_encode(csrf_token()); ?>);

        // Only offered once there is an identifier to look passkeys up by.
        // Showing it on an empty form invites pointless round trips.
        const sync = () => {
            wrapper.hidden = lrn.value.trim().length !== 12;
        };
        lrn.addEventListener('input', sync);
        sync();

        button.addEventListener('click', async () => {
            button.disabled = true;
            status.textContent = 'Follow your device prompt...';

            try {
                const result = await api.authenticate({
                    role: 'student',
                    identifier: lrn.value.trim(),
                    endpoints: {
                        options: '../controllers/webauthn_login_options.php',
                        verify: '../controllers/webauthn_login_verify.php'
                    }
                });
                window.location.href = result.redirect;
            } catch (error) {
                status.textContent = error.message || api.explain(error);
                button.disabled = false;
            }
        });
    })();
    </script>
</body>
</html>

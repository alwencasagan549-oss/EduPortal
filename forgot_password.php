<?php
/**
 * Password reset request.
 *
 * This is the portal's only account-recovery path. Until it existed, a student
 * who forgot their password required manual database intervention, which is
 * also what would have made a lost passkey unrecoverable.
 *
 * The response is deliberately identical whether or not the account exists,
 * whether or not it has an email on file, and whether or not the message was
 * actually delivered. Anything else turns this page into an account
 * enumeration oracle and a mail-bomb trigger.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/libs/AuthService.php';
require_once __DIR__ . '/libs/RecaptchaService.php';
require_once __DIR__ . '/libs/Mailer.php';

$role = ((string) ($_GET['role'] ?? $_POST['role'] ?? 'student')) === 'teacher' ? 'teacher' : 'student';

$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDBConnection();
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));

    $neutralNotice = 'If that account exists and has an email address on file, a reset link is on its way.';

    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        auth_record_event($conn, 'password_reset_request', 'csrf_failure', ['user_role' => $role]);
        $error = 'Invalid security token.';
    } elseif ($identifier === '' || ($role === 'teacher' && $subject === '')) {
        $error = $role === 'teacher'
            ? 'Enter the email address and subject for your teaching account.'
            : 'Enter your Learner Reference Number.';
    } else {
        // Ahead of the account lookup, and it has to stay that way. A refusal
        // here says nothing about whether the account exists, so the neutral
        // response below is not weakened by adding the check; what it does
        // stop is the request ever reaching auth_find_student() or the
        // throttler, which is what turns this page into a mail-bomb primitive
        // aimed at whatever address is on file.
        $recaptcha = recaptcha_check($_POST, 'password_reset_request', [
            'conn' => $conn,
            'user_role' => $role,
            'identifier' => $identifier,
        ]);

        if (!$recaptcha['ok']) {
            $error = $recaptcha['error'];
        } else {
        // Throttled harder than a password guess: every accepted request
        // sends mail, so this endpoint can be aimed at a victim's inbox.
        $buckets = [
            auth_rate_limit_bucket_key('reset-ip', auth_client_ip()),
            auth_rate_limit_bucket_key('reset-account', $role . '|' . ($role === 'student' ? $identifier : $identifier . '|' . $subject)),
        ];

        $lockedFor = auth_rate_limit_check($conn, $buckets);
        if ($lockedFor !== null && $lockedFor > 0) {
            auth_record_event($conn, 'password_reset_request', 'rate_limited', [
                'user_role' => $role,
                'identifier' => $identifier,
            ]);
            $error = 'Too many reset requests. Please wait ' . $lockedFor . ' seconds.';
        } else {
            $account = $role === 'student'
                ? auth_find_student($conn, $identifier)
                : auth_find_teacher($conn, $identifier, $subject);

            $email = is_array($account) ? trim((string) ($account['email'] ?? '')) : '';
            $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';

            if ($account === null || $email === '') {
                auth_rate_limit_failure($conn, $buckets);
                auth_record_event($conn, 'password_reset_request', 'unknown_account', [
                    'user_role' => $role,
                    'identifier' => $identifier,
                ]);
                $notice = $neutralNotice;
            } elseif (!auth_mail_configured()) {
                // Checked before a token is minted, so an unconfigured
                // deployment does not accumulate single-use tokens nobody can
                // ever redeem, and does not burn the user's rate-limit budget
                // on a request that was never going to succeed.
                auth_rate_limit_failure($conn, $buckets);
                auth_record_event($conn, 'password_reset_request', 'mail_unconfigured', [
                    'user_role' => $role,
                    'user_id' => $account['id'],
                    'identifier' => $identifier,
                ]);
                // Deliberately distinct from a transient send failure further
                // down. A missing relay cannot be retried away, and telling
                // the user to "try again later" sends them in circles while
                // the operator has no idea the feature is switched off.
                $error = 'Password reset email is not configured on this portal. '
                    . 'Please contact the portal administrator.';
            } else {
                $token = auth_issue_token($conn, 'password_reset', $role, $account['id']);

                if ($token === null) {
                    auth_rate_limit_failure($conn, $buckets);
                    auth_record_event($conn, 'password_reset_request', 'failure', [
                        'user_role' => $role,
                        'user_id' => $account['id'],
                        'identifier' => $identifier,
                    ]);
                    $error = 'Password reset is temporarily unavailable. Please contact the portal administrator.';
                } else {
                    $link = rtrim((string) SITE_URL, '/') . '/reset_password.php?token=' . urlencode($token);
                    $displayName = (string) ($account['name'] ?? 'there');
                    $roleLabel = $role === 'teacher' ? 'Teacher' : 'Student';

                    $html = '<p>Hello ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ',</p>'
                        . '<p>A password reset was requested for your ' . $roleLabel . ' EduPortal account.</p>'
                        . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Choose a new password</a></p>'
                        . '<p>This link expires in 30 minutes and can only be used once. '
                        . 'If you did not request this, no action is needed and your password is unchanged.</p>';

                    $text = "Hello {$displayName},\n\n"
                        . "A password reset was requested for your {$roleLabel} EduPortal account.\n\n"
                        . "Choose a new password: {$link}\n\n"
                        . "This link expires in 30 minutes and can only be used once. "
                        . "If you did not request this, no action is needed and your password is unchanged.\n";

                    $sent = auth_send_mail($email, 'EduPortal password reset', $html, $text);

                    if ($sent) {
                        auth_rate_limit_clear($conn, $buckets, null);
                        auth_record_event($conn, 'password_reset_request', 'success', [
                            'user_role' => $role,
                            'user_id' => $account['id'],
                            'identifier' => $identifier,
                        ]);
                        $notice = $neutralNotice;
                    } else {
                        auth_rate_limit_failure($conn, $buckets);
                        auth_record_event($conn, 'password_reset_request', 'mail_failure', [
                            'user_role' => $role,
                            'user_id' => $account['id'],
                            'identifier' => $identifier,
                            // The relay's own response, so an operator can read
                            // why a send failed with a query instead of hunting
                            // through a host log viewer. Credentials are
                            // redacted before it ever gets here.
                            'detail' => auth_last_mail_error(),
                        ]);
                        // Mail IS configured, so this is a relay-side problem:
                        // bad credentials, a rejected sender, a rate limit.
                        // Retrying is genuinely worth suggesting here.
                        $error = 'We could not send the reset email right now. Please try again later, '
                            . 'or contact the portal administrator.';
                    }
                }
            }
        }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo human_gate_head(); ?>
    <?php echo google_analytics_tag(); ?>
    <title>Password Reset | EduPortal LMS</title>
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
                <i class="fas fa-key" style="font-size: 1.5rem;"></i>
            </div>
            <h1 class="auth-title">Reset Password</h1>
            <p class="auth-subtitle">
                <?php echo $role === 'teacher' ? 'Recover access to your teaching account' : 'Recover access to your student account'; ?>
            </p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-circle-exclamation"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($notice): ?>
            <div class="alert alert-success">
                <i class="fas fa-circle-check"></i>
                <div><?php echo htmlspecialchars($notice); ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" data-loader="true" data-recaptcha-action="password_reset_request">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="role" value="<?php echo htmlspecialchars($role); ?>">

            <div style="margin-bottom: 1.5rem;">
                <label for="identifier" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas <?php echo $role === 'teacher' ? 'fa-envelope' : 'fa-hashtag'; ?>"></i>
                    <?php echo $role === 'teacher' ? 'Professional Email' : 'Learner Reference Number (LRN)'; ?>
                </label>
                <input type="<?php echo $role === 'teacher' ? 'email' : 'text'; ?>"
                       id="identifier" name="identifier" autocomplete="username" required
                       class="premium-input" <?php echo $role === 'student' ? 'inputmode="numeric" maxlength="12" pattern="\d{12}"' : ''; ?>
                       placeholder="<?php echo $role === 'teacher' ? 'name@school.com' : 'Enter your 12-digit LRN'; ?>">
            </div>

            <?php if ($role === 'teacher'): ?>
                <div style="margin-bottom: 1.5rem;">
                    <label for="subject" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                        <i class="fas fa-book"></i> Subject
                    </label>
                    <input type="text" id="subject" name="subject" autocomplete="organization-title" required
                           class="premium-input" placeholder="e.g., Mathematics">
                </div>
            <?php endif; ?>

            <button type="submit" class="premium-btn premium-btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">
                <i class="fas fa-paper-plane"></i> Send Reset Link
            </button>
        </form>

        <div style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; text-align: center;">
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1rem;">
                The reset link is emailed to the address on your account and expires in 30 minutes.
            </p>
            <a href="<?php echo $role === 'teacher' ? 'teacher/login.php' : 'student/login.php'; ?>"
               class="premium-btn premium-btn-outline" style="width: 100%; justify-content: center;">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
    </main>
    <script src="assets/js/trusted_types.js"></script>
    <?php echo recaptcha_script_tag(); ?>
    <script src="assets/js/system_loader.js?v=20260924-loader4"></script>
    <script src="assets/js/responsive_ui.js"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>

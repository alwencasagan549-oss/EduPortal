<?php
// One-time admin password reset - DELETE AFTER USE
require_once __DIR__ . '/config/database.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($new_password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($new_password, PASSWORD_BCRYPT);
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE admin SET password = ? WHERE username = 'admin'");
        $stmt->execute([$hash]);

        if ($stmt->rowCount() > 0) {
            $message = 'Password reset successful! You can now login with username <strong>admin</strong> and your new password.';
        } else {
            $error = 'Admin account not found. Run setup.php first.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Admin Password | EduPortal</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    </style>
</head>
<body>
    <div class="auth-card" style="max-width: 420px; width: 90%;">
        <div class="auth-logo">
            <div class="sidebar-logo" style="margin: 0 auto 1.5rem; width: 60px; height: 60px;">
                <i class="fas fa-key" style="font-size: 1.5rem;"></i>
            </div>
            <h1 class="auth-title">Reset Admin Password</h1>
            <p class="auth-subtitle">Set a new password for the admin account</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><div><?php echo $message; ?></div></div>
            <p style="text-align: center; margin-top: 1.5rem;">
                <a href="admin/login.php" class="premium-btn premium-btn-primary" style="padding: 0.7rem 1.5rem;">
                    <i class="fas fa-right-to-bracket"></i> Go to Login
                </a>
            </p>
            <p style="text-align: center; margin-top: 1rem; color: var(--text-muted); font-size: 0.75rem;">
                <strong>Delete this file (reset_admin.php) now for security.</strong>
            </p>
        <?php elseif ($error): ?>
            <div class="alert alert-danger"><div><?php echo htmlspecialchars($error); ?></div></div>
        <?php endif; ?>

        <?php if (!$message): ?>
        <form method="POST">
            <div style="margin-bottom: 1.25rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-lock"></i> New Password
                </label>
                <input type="password" name="password" required class="premium-input" placeholder="Min 8 characters" minlength="8">
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-check-double"></i> Confirm Password
                </label>
                <input type="password" name="confirm" required class="premium-input" placeholder="Repeat password">
            </div>
            <button type="submit" class="premium-btn premium-btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">
                <i class="fas fa-rotate"></i> Reset Password
            </button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>

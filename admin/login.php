<?php
require_once '../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Auth Shield: Throttling Logic
    if (isset($_SESSION['login_timeout']) && time() < $_SESSION['login_timeout']) {
        $wait = $_SESSION['login_timeout'] - time();
        $error = "Too many failed attempts. Please wait $wait seconds.";
    } else {
        if (!validate_csrf($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid security token.';
        } else {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT * FROM admin WHERE username = ?");
        $stmt->execute([$username]);
        $result = $stmt->get_result();

        $login_success = false;
        if ($result->num_rows() === 1) {
            $admin = $result->fetch_assoc();
            if (password_verify($password, $admin['password'])) {
                $login_success = true;
                
                // Auth Shield: Regenerate Session for Security
                session_regenerate_id(true);
                
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['user_role'] = 'admin';
                
                // Clear attempts on success
                unset($_SESSION['login_attempts']);
                unset($_SESSION['login_timeout']);
                
                header('Location: dashboard.php');
                exit();
            }
        }

        if (!$login_success) {
            // Auth Shield: 5-Strike Throttling
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            if ($_SESSION['login_attempts'] >= 5) {
                $_SESSION['login_timeout'] = time() + 30; // 30-second cooldown
                $error = "Too many failed attempts. Please wait 30 seconds.";
            } else {
                $error = "Invalid Username or Password."; // Generic Error
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
    <title>Admin Login | EduPortal LMS</title>
    <link rel="icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="sidebar-logo" style="margin: 0 auto 1.5rem; width: 60px; height: 60px;">
                <i class="fas fa-user-shield" style="font-size: 1.5rem;"></i>
            </div>
            <h1 class="auth-title">Admin Access</h1>
            <p class="auth-subtitle">System Administration & Management</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-triangle-exclamation"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" data-loader="true">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-user"></i> Username
                </label>
                <input type="text" name="username" required class="premium-input" placeholder="Enter original username">
            </div>
            
            <div style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-lock"></i> Password
                </label>
                <input type="password" name="password" required class="premium-input" placeholder="••••••••">
            </div>
            
            <button type="submit" class="premium-btn premium-btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">
                <i class="fas fa-right-to-bracket"></i> Authenticate
            </button>
        </form>

        <div style="margin-top: 2.5rem; border-top: 1px solid var(--glass-border); padding-top: 1rem; text-align: center; color: var(--text-muted); font-size: 0.8rem;">
            <p>&copy; 2026 EduPortal. Web Developer: <strong id="_sys_v_auth"><a href="https://casagan.vercel.app/" target="_blank" style="color: inherit; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwen T. Casagan</a></strong></p>
            <a href="../index.php" style="color: var(--text-muted); text-decoration: none; display: inline-block; margin-top: 10px; transition: color 0.2s;" onmouseover="this.style.color='var(--text-main)'" onmouseout="this.style.color='var(--text-muted)'">
                <i class="fas fa-arrow-left"></i> Back to Main Portal
            </a>
        </div>
    <script src="../assets/js/system_loader.js"></script>
</body>
</html>
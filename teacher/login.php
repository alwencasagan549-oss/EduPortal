<?php
session_start();
require_once '../config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Auth Shield: Throttling Logic
    if (isset($_SESSION['login_timeout']) && time() < $_SESSION['login_timeout']) {
        $wait = $_SESSION['login_timeout'] - time();
        $error = "Too many failed attempts. Please wait $wait seconds.";
    } else {
        $email = $_POST['email'];
        $subject = $_POST['subject'];
        $password = $_POST['password'];
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT * FROM teachers WHERE email = ? AND subject = ?");
        $stmt->execute([$email, $subject]);
        $result = $stmt->get_result();

        $login_success = false;
        if ($result->num_rows() === 1) {
            $teacher = $result->fetch_assoc();
            if (password_verify($password, $teacher['password'])) {
                $login_success = true;
                
                // Auth Shield: Regenerate Session for Security
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $teacher['id'];
                $_SESSION['user_name'] = $teacher['name'];
                $_SESSION['user_email'] = $teacher['email'];
                $_SESSION['user_subject'] = $teacher['subject'];
                $_SESSION['user_role'] = 'teacher';
                
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
                $error = "Invalid Email or Password."; // Generic Error
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
    <title>Teacher Login | EduPortal LMS</title>
    <link rel="icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="sidebar-logo" style="margin: 0 auto 1.5rem; width: 60px; height: 60px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);">
                <i class="fas fa-chalkboard-teacher" style="font-size: 1.5rem;"></i>
            </div>
            <h1 class="auth-title">Teacher Portal</h1>
            <p class="auth-subtitle">Welcome back, educator!</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-circle-exclamation"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" data-loader="true">
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-envelope"></i> Professional Email
                </label>
                <input type="email" name="email" required class="premium-input" placeholder="name@school.com">
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-book"></i> Subject
                </label>
                <input type="text" name="subject" required class="premium-input" placeholder="e.g., Mathematics">
            </div>
            
            <div style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-shield-halved"></i> Security Password
                </label>
                <input type="password" name="password" required class="premium-input" placeholder="••••••••">
            </div>
            
            <button type="submit" class="premium-btn" style="width: 100%; justify-content: center; padding: 1rem; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);">
                <i class="fas fa-right-to-bracket"></i> Login to Dashboard
            </button>
        </form>

        <div style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; text-align: center;">
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">New faculty member?</p>
            <a href="signup.php" class="premium-btn premium-btn-outline" style="width: 100%; justify-content: center;">
                <i class="fas fa-user-plus"></i> Request Teaching Access
            </a>
            <div style="margin-top: 2.5rem; border-top: 1px solid var(--glass-border); padding-top: 1rem; text-align: center; color: var(--text-muted); font-size: 0.8rem;">
                <p>&copy; 2026 EduPortal. Web Developer: <strong id="_sys_v_auth"><a href="https://casagan.vercel.app/" target="_blank" style="color: inherit; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwen T. Casagan</a></strong></p>
                <a href="../index.php" style="color: var(--text-muted); text-decoration: none; display: inline-block; margin-top: 10px; transition: color 0.2s;" onmouseover="this.style.color='var(--text-main)'" onmouseout="this.style.color='var(--text-muted)'" onclick="EduPortal.showLoader('Navigating...', 'Please wait while we redirect you.')">
                    <i class="fas fa-arrow-left"></i> Back to Main Portal
                </a>
            </div>
        </div>
    </div>
    <script src="../assets/js/system_loader.js"></script>
    <script src="../assets/js/responsive_ui.js"></script>
</body>
</html>
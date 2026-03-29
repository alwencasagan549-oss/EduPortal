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
        $lrn = $_POST['lrn'];
        $password = $_POST['password'];
        
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT * FROM students WHERE lrn = ?");
        $stmt->bind_param("s", $lrn);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $login_success = false;
        if ($result->num_rows === 1) {
            $student = $result->fetch_assoc();
            if (password_verify($password, $student['password'])) {
                $login_success = true;
                
                // Auth Shield: Regenerate Session for Security
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $student['id'];
                $_SESSION['user_name'] = $student['name'];
                $_SESSION['user_lrn'] = $student['lrn'];
                $_SESSION['user_grade'] = $student['grade_level'];
                $_SESSION['user_section'] = $student['section'];
                $_SESSION['user_role'] = 'student';
                
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
                $error = "Invalid LRN or Password."; // Generic Error
            }
        }
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login | EduPortal LMS</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="sidebar-logo" style="margin: 0 auto 1.5rem; width: 60px; height: 60px;">
                <i class="fas fa-graduation-cap" style="font-size: 1.5rem;"></i>
            </div>
            <h1 class="auth-title">Student Hub</h1>
            <p class="auth-subtitle">Welcome to your learning journey!</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-triangle-exclamation"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" data-loader="true">
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-hashtag"></i> Learner Reference Number (LRN)
                </label>
                <input type="text" name="lrn" required class="premium-input" 
                       placeholder="Enter your 12-digit LRN" maxlength="12" minlength="12" 
                       pattern="\d{12}" inputmode="numeric"
                       oninput="this.value = this.value.replace(/[^0-9]/g, '')">
            </div>
            
            <div style="margin-bottom: 2rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">
                    <i class="fas fa-key"></i> Password
                </label>
                <input type="password" name="password" required class="premium-input" placeholder="••••••••">
            </div>
            
            <button type="submit" class="premium-btn premium-btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">
                <i class="fas fa-right-to-bracket"></i> Login to Portal
            </button>
        </form>

        <div style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; text-align: center;">
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">Don't have an account yet?</p>
            <a href="signup.php" class="premium-btn premium-btn-outline" style="width: 100%; justify-content: center;">
                <i class="fas fa-user-plus"></i> Create Student Account
            </a>
            <div style="margin-top: 2.5rem; border-top: 1px solid var(--glass-border); padding-top: 1rem; text-align: center; color: var(--text-muted); font-size: 0.8rem;">
                <p>&copy; 2026 EduPortal. Web Developer: <strong id="_sys_v_auth"><a href="https://casagan.vercel.app/" target="_blank" style="color: inherit; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwen T. Casagan</a></strong></p>
                <a href="../index.php" style="color: var(--text-muted); text-decoration: none; display: inline-block; margin-top: 10px; transition: color 0.2s;" onmouseover="this.style.color='var(--text-main)'" onmouseout="this.style.color='var(--text-muted)'">
                    <i class="fas fa-arrow-left"></i> Home Page
                </a>
            </div>
        </div>
    </div>
    <script src="../assets/js/system_loader.js"></script>
</body>
</html>
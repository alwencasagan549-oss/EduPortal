<?php
session_start();
require_once '../config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $subject = trim($_POST['subject']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } else {
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        $conn = getDBConnection();
        // Check if email + subject combination already exists
        $check = $conn->prepare("SELECT id FROM teachers WHERE email = ? AND subject = ?");
        $check->execute([$email, $subject]);
        if ($check->get_result()->num_rows() > 0) {
            $error = "You are already registered for this subject ($subject)";
        } else {
            $stmt = $conn->prepare("INSERT INTO teachers (name, email, subject, password) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $email, $subject, $hashed_password]);

            if ($stmt->rowCount() > 0) {
                $success = "Registration successful! You can now login.";
            } else {
                $error = "Registration failed";
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
    <title>Teacher Registration | EduPortal LMS</title>
    <link rel="icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-page">
    <div class="auth-card" style="max-width: 550px;">
        <div class="auth-logo">
            <div class="sidebar-logo" style="margin: 0 auto 1.5rem; width: 60px; height: 60px;">
                <i class="fas fa-user-plus" style="font-size: 1.5rem;"></i>
            </div>
            <h1 class="auth-title">Teacher Signup</h1>
            <p class="auth-subtitle">Join our faculty of world-class teachers</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-circle-xmark"></i> <div><?php echo $error; ?></div></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-circle-check"></i> <div><?php echo $success; ?></div></div>
        <?php endif; ?>

        <form method="POST" data-loader="true">
            <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Full Name</label>
                    <input type="text" name="name" required class="premium-input" placeholder="Mr. John Doe">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Professional Email</label>
                    <input type="email" name="email" required class="premium-input" placeholder="john@school.com">
                </div>
            </div>
            
            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Specialization / Subject</label>
                <input type="text" name="subject" required class="premium-input" placeholder="Mathematics, Science, etc.">
            </div>
            
            <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Password</label>
                    <input type="password" name="password" required class="premium-input" placeholder="••••••••">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Confirm</label>
                    <input type="password" name="confirm_password" required class="premium-input" placeholder="••••••••">
                </div>
            </div>
            
            <button type="submit" class="premium-btn premium-btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">
                <i class="fas fa-user-check"></i> Create Teacher Account
            </button>
        </form>

        <div style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; text-align: center;">
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">Already have teacher credentials?</p>
            <a href="login.php" class="premium-btn premium-btn-outline" style="width: 100%; justify-content: center;">
                <i class="fas fa-right-to-bracket"></i> Instructor Login
            </a>
            <div style="margin-top: 2.5rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.8rem;">
                <p>&copy; 2026 EduPortal. Web Developer: <strong id="_sys_v_auth"><a href="https://casagan.vercel.app/" target="_blank" style="color: inherit; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwen T. Casagan</a></strong></p>
                <div style="margin-top: 1rem;">
                    <a href="../index.php" style="color: var(--text-muted); text-decoration: none; font-size: 0.85rem;" onmouseover="this.style.color='var(--text-main)'" onmouseout="this.style.color='var(--text-muted)'">
                        <i class="fas fa-arrow-left"></i> Home Page
                    </a>
                </div>
            </div>
        </div>
    </div>
    <script src="../assets/js/system_loader.js"></script>
    <script src="../assets/js/responsive_ui.js"></script>
</body>
</html>
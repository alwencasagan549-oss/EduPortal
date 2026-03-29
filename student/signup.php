<?php
session_start();
require_once '../config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lrn = trim($_POST['lrn']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($lrn) !== 12) {
        $error = "LRN must be exactly 12 digits";
    } else {
        $section = trim($_POST['section'] ?? '');
        $grade_level = trim($_POST['grade_level'] ?? 'Grade 11');
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        $conn = getDBConnection();
        // Check if LRN already exists (SQL Shield: Standardized Check)
        $check = $conn->prepare("SELECT id FROM students WHERE lrn = ?");
        $check->bind_param("s", $lrn);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            $error = "This LRN is already registered (Verification: Duplicate #$lrn)";
        } else {
            $check->close();
            
            $stmt = $conn->prepare("INSERT INTO students (lrn, name, email, grade_level, section, password) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $lrn, $name, $email, $grade_level, $section, $hashed_password);
            
            if ($stmt->execute()) {
                $success = "Registration successful! You can now login.";
            } else {
                // If the check somehow missed a duplicate (Race Condition)
                if ($conn->errno === 1062) {
                    $error = "This LRN is already registered in our system.";
                } else {
                    $error = "Registration failed: " . $conn->error;
                }
            }
            $stmt->close();
        }
        $conn->close();
        // Skip re-closing $check or $stmt as they are closed inside logic
        $check_closed = true;
        $stmt_closed = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration | EduPortal LMS</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="auth-page">
    <div class="auth-card" style="max-width: 550px;">
        <div class="auth-logo">
            <div class="sidebar-logo" style="margin: 0 auto 1.5rem; width: 60px; height: 60px;">
                <i class="fas fa-user-plus" style="font-size: 1.5rem;"></i>
            </div>
            <h1 class="auth-title">Create Account</h1>
            <p class="auth-subtitle">Join the EduPortal learning community</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-circle-xmark"></i> <div><?php echo $error; ?></div></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-circle-check"></i> <div><?php echo $success; ?></div></div>
        <?php endif; ?>

        <form method="POST" data-loader="true">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">LRN (12 Digits)</label>
                    <input type="text" name="lrn" required class="premium-input" 
                           placeholder="123456789012" maxlength="12" minlength="12" 
                           pattern="\d{12}" inputmode="numeric"
                           oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Full Name</label>
                    <input type="text" name="name" required class="premium-input" placeholder="John Doe">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Grade Level</label>
                    <select name="grade_level" required class="premium-input">
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                        <option value="Grade 11" selected>Grade 11</option>
                        <option value="Grade 12">Grade 12</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Section</label>
                    <input type="text" name="section" required class="premium-input" placeholder="e.g., HUMMS-A, STEM-B">
                </div>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Email Address (Optional)</label>
                <input type="email" name="email" class="premium-input" placeholder="john@example.com">
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
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
                <i class="fas fa-user-check"></i> Register Student Account
            </button>
        </form>

        <div style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; text-align: center;">
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">Already have an account?</p>
            <a href="login.php" class="premium-btn premium-btn-outline" style="width: 100%; justify-content: center;">
                <i class="fas fa-right-to-bracket"></i> Login Instead
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
</body>
</html>
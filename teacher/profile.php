<?php
require_once '../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

if (getUserRole() !== 'teacher') {
    header('Location: /session_expired.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['user_name'];
$teacher_subject = $_SESSION['user_subject'];
$success_msg = '';
$error_msg = '';

$conn = getDBConnection();

// Get teacher's current information
$stmt = $conn->prepare("SELECT id, name, email, subject, created_at FROM teachers WHERE id = ?");
$stmt->execute([$teacher_id]);
$teacher = $stmt->get_result()->fetch_assoc();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Invalid security token.';
    }
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $subject = trim($_POST['subject']);
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    if (empty($name) || empty($email) || empty($subject)) {
        $errors[] = "Essential fields are required.";
    }
    
    // Check email uniqueness but allow same email for different subject (per previous requirements)
    $stmt = $conn->prepare("SELECT id FROM teachers WHERE email = ? AND subject = ? AND id != ?");
    $stmt->execute([$email, $subject, $teacher_id]);
    if ($stmt->get_result()->num_rows() > 0) {
        $errors[] = "An account with this email for this subject already exists.";
    }
    $stmt->close();
    
    if (!empty($new_password)) {
        if (empty($current_password)) {
            $errors[] = "Current password is required to set a new one.";
        } else {
            $stmt = $conn->prepare("SELECT password FROM teachers WHERE id = ?");
            $stmt->execute([$teacher_id]);
            $stored_pass = $stmt->get_result()->fetch_assoc()['password'];
            
            if (!password_verify($current_password, $stored_pass)) {
                $errors[] = "Current password verification failed.";
            } elseif ($new_password !== $confirm_password) {
                $errors[] = "New passwords do not match.";
            } elseif (strlen($new_password) < 6) {
                $errors[] = "Password must be at least 6 characters.";
            }
        }
    }
    
    if (empty($errors)) {
        if (!empty($new_password)) {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE teachers SET name = ?, email = ?, subject = ?, password = ? WHERE id = ?");
            $stmt->execute([$name, $email, $subject, $hashed, $teacher_id]);
        } else {
            $stmt = $conn->prepare("UPDATE teachers SET name = ?, email = ?, subject = ? WHERE id = ?");
            $stmt->execute([$name, $email, $subject, $teacher_id]);
        }

        if ($stmt->rowCount() > 0) {
            $_SESSION['user_name'] = $name;
            $_SESSION['user_subject'] = $subject;
            $success_msg = "Account security and profile updated successfully.";

            $teacher['name'] = $name;
            $teacher['email'] = $email;
            $teacher['subject'] = $subject;
        } else {
            $error_msg = "Update failed";
        }
    } else {
        $error_msg = implode(" ", $errors);
    }
}

require_once __DIR__ . '/nav.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="../assets/pwa-icon-192.svg" type="image/svg+xml">
    <link rel="manifest" href="../manifest.webmanifest">
    <meta name="theme-color" content="#0a0b10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="../assets/pwa-icon-192.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Profile | EduPortal LMS</title>
    <link rel="stylesheet" href="../assets/style.min.css?v=20260924">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <script src="../assets/js/system_loader.js?v=20260924-loader3"></script>
    <script src="../assets/js/responsive_ui.js"></script>
    <script src="../assets/js/pwa.js"></script>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="layout-wrapper">
        <?php renderTeacherNav('profile', $teacher_subject, $teacher_name); ?>

        <!-- Main Content -->
        <main class="main-content" id="main-content">
            <header class="top-bar">
                <button class="menu-toggle" type="button" aria-label="Open navigation" aria-controls="teacher-sidebar" aria-expanded="false">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>
                <div class="page-title">
                    <h1>Teacher Settings</h1>
                    <p>Manage your professional identity and security.</p>
                </div>
                
                <div class="top-bar-actions">
                    <button class="icon-button">
                        <i class="fas fa-bell"></i>
                    </button>
                </div>
            </header>

            <?php if ($success_msg): ?>
                <div class="alert alert-success animate-fade-up">
                    <i class="fas fa-circle-check"></i> <div><?php echo htmlspecialchars($success_msg); ?></div>
                </div>
            <?php elseif ($error_msg): ?>
                <div class="alert alert-danger animate-fade-up">
                    <i class="fas fa-circle-exclamation"></i> <div><?php echo htmlspecialchars($error_msg); ?></div>
                </div>
            <?php endif; ?>

            <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 350px; gap: 2rem; align-items: start;">
                <!-- Main Form -->
                <div class="glass-card" style="padding: 2.5rem;">
                    <form method="POST" data-loader="true">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <h2 style="margin-bottom: 2rem; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-id-card" style="color: var(--primary-color)"></i> General Information
                        </h2>
                        
                        <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Full Name</label>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($teacher['name']); ?>" required class="premium-input">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($teacher['email']); ?>" required class="premium-input">
                            </div>
                        </div>

                        <div style="margin-bottom: 3rem;">
                            <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Specialization / Subject</label>
                            <input type="text" name="subject" value="<?php echo htmlspecialchars($teacher['subject']); ?>" required class="premium-input">
                        </div>

                        <h2 style="margin-bottom: 2rem; display: flex; align-items: center; gap: 10px; border-top: 1px solid var(--glass-border); pt: 2rem;">
                            <i class="fas fa-shield-halved" style="color: var(--primary-color)"></i> Security Check
                        </h2>

                        <div style="margin-bottom: 1.5rem;">
                            <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Current Password (required for changes)</label>
                            <input type="password" name="current_password" class="premium-input" placeholder="••••••••">
                        </div>

                        <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2.5rem;">
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">New Password</label>
                                <input type="password" name="new_password" class="premium-input" placeholder="Min 6 characters">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="premium-input" placeholder="••••••••">
                            </div>
                        </div>

                        <div style="display: flex; gap: 1rem;">
                            <button type="submit" name="update_profile" class="premium-btn premium-btn-primary" style="padding: 1rem 2rem;">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <a href="dashboard.php" class="premium-btn premium-btn-outline">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Profile Sidebar -->
                <div style="display: flex; flex-direction: column; gap: 2rem;">
                    <div class="glass-card" style="text-align: center; padding: 3rem 2rem;">
                        <div style="width: 100px; height: 100px; background: var(--primary-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 2.5rem; color: white; box-shadow: 0 10px 25px rgba(78, 115, 223, 0.4);">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <h2 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($teacher['name']); ?></h2>
                        <p style="color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-size: 0.8rem; font-weight: 700; margin-bottom: 1.5rem;">Member Since <?php echo date('Y', strtotime($teacher['created_at'])); ?></p>
                        
                        <div style="display: flex; flex-direction: column; gap: 0.8rem; text-align: left; background: rgba(0,0,0,0.2); padding: 1.5rem; border-radius: 16px;">
                            <div style="font-size: 0.85rem;">
                                <span style="color: var(--text-muted); display: block; margin-bottom: 2px;">Teaching Subject</span>
                                <span style="font-weight: 600;"><?php echo htmlspecialchars($teacher['subject']); ?></span>
                            </div>
                            <div style="font-size: 0.85rem;">
                                <span style="color: var(--text-muted); display: block; margin-bottom: 2px;">Email Address</span>
                                <span style="font-weight: 600;"><?php echo htmlspecialchars($teacher['email']); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="glass-card" style="padding: 1.5rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, transparent 100%);">
                        <h3 style="margin-bottom: 1rem; color: var(--success-color);"><i class="fas fa-circle-check" style="margin-right: 8px;"></i> Security Tip</h3>
                        <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">Ensure your password is unique and complex. Regularly update your security settings to protect student data.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>

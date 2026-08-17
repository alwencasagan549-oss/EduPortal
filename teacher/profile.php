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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="../assets/favicon.ico" type="image/x-icon">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Profile | EduPortal LMS</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="../assets/js/system_loader.js"></script>
    <script src="../assets/js/responsive_ui.js"></script>
</head>
<body>
    <div class="layout-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="sidebar-brand">
                    Edu<span>Portal</span>
                </div>
            </div>
            
            <nav class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" class="menu-link" onclick="EduPortal.navigate('Dashboard', 'Loading dashboard...', this)">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php" class="menu-link active" onclick="EduPortal.navigate('Profile', 'Loading profile settings...', this)">
                        <i class="fas fa-user-circle"></i> Profile
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php" class="menu-link" onclick="EduPortal.navigate('My Students', 'Loading student directory...', this)">
                        <i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($teacher_subject); ?> Students
                    </a>
                </li>
            </nav>
            
            <div class="sidebar-footer">
                <div class="user-snippet">
                    <div class="avatar-small">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="user-snippet-info">
                        <div class="user-name"><?php echo htmlspecialchars($teacher['name']); ?></div>
                        <div class="user-status"><i class="fas fa-circle" style="font-size: 0.5rem"></i> Online</div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-link" onclick="return EduPortal.confirmLogout(this)">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
                <div style="padding: 8px 0 0; text-align: center; opacity: 0.4; font-size: 0.65rem; color: var(--text-muted);">
                    <span id="_sys_v_auth" style="display: none;">Alwen T. Casagan</span>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="top-bar">
                <button class="menu-toggle">
                    <i class="fas fa-bars"></i>
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
                        <h3 style="margin-bottom: 2rem; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-id-card" style="color: var(--primary-color)"></i> General Information
                        </h3>
                        
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

                        <h3 style="margin-bottom: 2rem; display: flex; align-items: center; gap: 10px; border-top: 1px solid var(--glass-border); pt: 2rem;">
                            <i class="fas fa-shield-halved" style="color: var(--primary-color)"></i> Security Check
                        </h3>

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
                        <h4 style="margin-bottom: 1rem; color: var(--success-color);"><i class="fas fa-circle-check" style="margin-right: 8px;"></i> Security Tip</h4>
                        <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">Ensure your password is unique and complex. Regularly update your security settings to protect student data.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
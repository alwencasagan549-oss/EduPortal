<?php
require_once '../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

if (getUserRole() !== 'teacher') {
    header('Location: index.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['user_name'];
$teacher_email = $_SESSION['user_email'] ?? 'teacher@eduportal.com';
$teacher_subject = $_SESSION['user_subject'];

// Get student details from ID
$student_id = intval($_GET['id'] ?? 0);
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT name, email, lrn, grade_level, section FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    header('Location: students.php');
    exit();
}

// Pre-fill Subject
$default_subject = "[EduPortal] Academic Concern: " . $teacher_subject;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Student | EduPortal LMS</title>
    <link rel="icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="../assets/style.css?v=1.3">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <div class="layout-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-envelope-open-text"></i>
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
                    <a href="profile.php" class="menu-link" onclick="EduPortal.navigate('Profile', 'Loading profile settings...', this)">
                        <i class="fas fa-user-circle"></i> Profile
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php" class="menu-link active" onclick="EduPortal.navigate('My Students', 'Loading student directory...', this)">
                        <i class="fas fa-user-graduate"></i> <?php echo htmlspecialchars($teacher_subject); ?> Students
                    </a>
                </li>
            </nav>

            <div class="sidebar-footer">
                <div class="user-snippet">
                    <div class="avatar-small">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="user-snippet-info">
                        <div class="user-name"><?php echo htmlspecialchars($teacher_name); ?></div>
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
                <div class="page-title">
                    <h1>Contact Student</h1>
                    <p>Drafting a message about <strong><?php echo htmlspecialchars($student['name']); ?></strong>
                        regarding <strong><?php echo htmlspecialchars($teacher_subject); ?></strong></p>
                </div>

                <div class="top-bar-actions">
                    <a href="students.php" class="premium-btn premium-btn-outline"
                        style="padding: 0.6rem 1rem;">
                        <i class="fas fa-arrow-left"></i> Back to Directory
                    </a>
                </div>
            </header>

            <div id="statusAlert"></div>

            <div style="display: grid; grid-template-columns: 1fr 380px; gap: 2rem; align-items: start;">
                <!-- Message Composer -->
                <div class="glass-card" style="padding: 2.5rem; position: relative; overflow: hidden;">
                    <div
                        style="position: absolute; top: -50px; right: -50px; width: 150px; height: 150px; background: var(--primary-gradient); opacity: 0.1; border-radius: 50%; blur: 50px;">
                    </div>

                    <h3 style="margin-bottom: 2rem; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-pen-nib" style="color: var(--primary-color)"></i> Message Composer
                    </h3>

                    <form id="contactForm" method="POST" data-loader="true">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                            <div>
                                <label
                                    style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">To
                                    (Student)</label>
                                <input type="text" value="<?php echo htmlspecialchars($student['email']); ?>" readonly
                                    class="premium-input"
                                    style="background: rgba(255,255,255,0.02); cursor: not-allowed; color: var(--text-muted);">
                            </div>
                            <div>
                                <label
                                    style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">From
                                    (Teacher)</label>
                                <input type="text" value="<?php echo htmlspecialchars($teacher_email); ?>" readonly
                                    class="premium-input"
                                    style="background: rgba(255,255,255,0.02); cursor: not-allowed; color: var(--text-muted);">
                            </div>
                        </div>

                        <div style="margin-bottom: 2rem;">
                            <label
                                style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Email
                                Subject</label>
                            <input type="text" name="subject" value="<?php echo htmlspecialchars($default_subject); ?>"
                                required class="premium-input">
                        </div>

                        <div style="margin-bottom: 2.5rem;">
                            <label
                                style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Message
                                Content</label>
                            <textarea name="message" rows="8" class="premium-input" style="resize: none;" required
                                placeholder="Type your concerns about the student's academic performance here..."></textarea>
                        </div>

                        <button type="submit" name="send_message" class="premium-btn premium-btn-primary"
                            style="padding: 1rem 3rem; width: 100%; justify-content: center; font-size: 1.1rem; box-shadow: 0 10px 25px rgba(78, 115, 223, 0.4);">
                            <i class="fas fa-paper-plane" style="margin-right: 12px;"></i> Send via EduPortal Server
                        </button>
                    </form>

                    <p style="text-align: center; margin-top: 1.5rem; color: var(--text-muted); font-size: 0.8rem;">
                        <i class="fas fa-server"></i> This message is processed safely by our internal mail system.
                    </p>
                </div>

                <!-- Student Profile Sidebar -->
                <div class="glass-card" style="padding: 2rem; border-left: 3px solid var(--primary-color);">
                    <h4 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-user-graduate" style="color: var(--primary-color)"></i> Student Context
                    </h4>

                    <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                        <div style="background: rgba(0,0,0,0.2); border-radius: 12px; padding: 1.25rem;">
                            <span
                                style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Student
                                Name</span>
                            <div style="font-size: 1.1rem; font-weight: 600; margin-top: 4px;">
                                <?php echo htmlspecialchars($student['name']); ?></div>
                        </div>

                        <div style="background: rgba(0,0,0,0.2); border-radius: 12px; padding: 1.25rem;">
                            <span
                                style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Grade
                                & Section</span>
                            <div style="font-weight: 600; margin-top: 4px;">
                                <?php echo htmlspecialchars($student['grade_level']); ?> |
                                <?php echo htmlspecialchars($student['section']); ?></div>
                        </div>

                        <div style="background: rgba(0,0,0,0.2); border-radius: 12px; padding: 1.25rem;">
                            <span
                                style="color: var(--text-muted); font-size: 0.75rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">LRN
                                Number</span>
                            <div style="font-weight: 600; margin-top: 4px;">
                                <?php echo htmlspecialchars($student['lrn']); ?></div>
                        </div>

                        <div
                            style="padding: 1rem; border: 1px dashed var(--glass-border); border-radius: 12px; font-size: 0.8rem; color: var(--text-muted); line-height: 1.5;">
                            Use this form to message the student about missing assignments, low marks, or to provide
                            personalized guidance.
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/system_loader.js"></script>
    <script>
        document.getElementById('contactForm').onsubmit = function (e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('send_message', 'true');

            // Show global loader instantly
            EduPortal.showLoader("Analyzing Request", "Queuing your message for priority delivery...");

            fetch('../controllers/ajax_send_email.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    EduPortal.showSuccessModal(
                        "Message Dispatched", 
                        "Your email has been queued and is being delivered in the background."
                    );
                    
                    // Trigger the background worker silently without showing the traffic loader yet
                    fetch('../controllers/process_job.php?action=process');
                } else {
                    EduPortal.hideLoader();
                    const statusAlert = document.getElementById('statusAlert');
                    statusAlert.innerHTML = `
                        <div class="alert alert-danger animate-fade-up">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div id="errorMessage"></div>
                        </div>
                    `;
                    document.getElementById('errorMessage').textContent = data.error || "Submission failed. Please check all fields.";
                }
            })
            .catch(err => {
                EduPortal.hideLoader();
                console.error(err);
            });
            
            return false;
        };
    </script>
</body>

</html>
<?php
require_once '../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

if (getUserRole() !== 'student') {
    header('Location: /session_expired.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'];
$student_lrn = $_SESSION['user_lrn'];
$student_grade = $_SESSION['user_grade'] ?? '';
$student_section = $_SESSION['user_section'] ?? '';
$student_strand = $_SESSION['user_strand'] ?? 'Academic';

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT COUNT(*) AS total_submissions, COALESCE(SUM(CASE WHEN NULLIF(TRIM(marks), '') IS NOT NULL THEN 1 ELSE 0 END), 0) AS graded_count FROM submissions WHERE student_id = ?");
$stmt->execute([$student_id]);
$stats = $stmt->fetch_assoc() ?: [];
$total_submissions = (int) ($stats['total_submissions'] ?? 0);
$graded_count = (int) ($stats['graded_count'] ?? 0);
$pending_count = $total_submissions - $graded_count;

$email_stmt = $conn->prepare("SELECT email FROM students WHERE id = ?");
$email_stmt->execute([$student_id]);
$email_row = $email_stmt->fetch_assoc();
$student_email = is_array($email_row) ? ($email_row['email'] ?? ($_SESSION['user_email'] ?? '')) : ($_SESSION['user_email'] ?? '');

require_once __DIR__ . '/nav.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile | EduPortal LMS</title>
    <link rel="icon" href="../assets/favicon.ico?v=20260924-ico" type="image/x-icon">
    <link rel="manifest" href="../manifest.webmanifest">
    <meta name="theme-color" content="#0a0b10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="../assets/pwa-icon-192.svg">
    <link rel="stylesheet" href="../assets/style.min.css?v=20260924">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="layout-wrapper">
        <?php renderStudentNav('profile'); ?>

        <!-- Main Content -->
        <main class="main-content" id="main-content">
            <header class="top-bar">
                <button class="menu-toggle" type="button" aria-label="Open navigation" aria-controls="student-sidebar" aria-expanded="false">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>
                <div class="page-title">
                    <h1>Student Profile</h1>
                    <p>Review your account information and submission history.</p>
                </div>
            </header>

            <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 350px; gap: 2rem; align-items: start;">
                <div class="glass-card" style="padding: 2.5rem;">
                    <h2 style="margin-bottom: 2rem; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-id-card" style="color: var(--primary-color)"></i> Account Information
                    </h2>

                    <div style="margin-bottom: 1.5rem;">
                        <label for="student-name" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Full Name</label>
                        <input id="student-name" name="student_name" type="text" value="<?php echo htmlspecialchars($student_name); ?>" readonly class="premium-input" style="background: rgba(255,255,255,0.02); color: var(--text-muted); cursor: not-allowed;">
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <label for="student-lrn" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">LRN</label>
                        <input id="student-lrn" name="student_lrn" type="text" value="<?php echo htmlspecialchars($student_lrn); ?>" readonly class="premium-input" style="background: rgba(255,255,255,0.02); color: var(--text-muted); cursor: not-allowed;">
                    </div>

                    <div style="margin-bottom: 1.5rem;">
                        <label for="student-email" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Email Address</label>
                        <input id="student-email" name="student_email" type="email" value="<?php echo htmlspecialchars($student_email); ?>" readonly class="premium-input" style="background: rgba(255,255,255,0.02); color: var(--text-muted); cursor: not-allowed;">
                    </div>

                    <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                        <div>
                            <label for="student-grade" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Grade Level</label>
                            <input id="student-grade" name="student_grade" type="text" value="<?php echo htmlspecialchars($student_grade); ?>" readonly class="premium-input" style="background: rgba(255,255,255,0.02); color: var(--text-muted); cursor: not-allowed;">
                        </div>
                        <div>
                            <label for="student-section" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Section</label>
                            <input id="student-section" name="student_section" type="text" value="<?php echo htmlspecialchars($student_section); ?>" readonly class="premium-input" style="background: rgba(255,255,255,0.02); color: var(--text-muted); cursor: not-allowed;">
                        </div>
                        <div>
                            <label for="student-strand" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Strand</label>
                            <input id="student-strand" name="student_strand" type="text" value="<?php echo htmlspecialchars($student_strand); ?>" readonly class="premium-input" style="background: rgba(255,255,255,0.02); color: var(--text-muted); cursor: not-allowed;">
                        </div>
                    </div>

                    <div style="border-top: 1px solid var(--glass-border); padding-top: 2rem;">
                        <h2 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-shield-halved" style="color: var(--primary-color)"></i> Security
                        </h2>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">
                            For security reasons, password changes must be requested through your teacher or system administrator.
                        </p>
                        <a href="dashboard.php" class="premium-btn premium-btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 2rem;">
                    <div class="glass-card" style="text-align: center; padding: 3rem 2rem;">
                        <div style="width: 100px; height: 100px; background: var(--primary-gradient); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 2.5rem; color: white; box-shadow: 0 10px 25px rgba(78, 115, 223, 0.4);">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <h2 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($student_name); ?></h2>
                        <p style="color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-size: 0.8rem; font-weight: 700; margin-bottom: 1.5rem;">Student</p>

                        <div style="display: flex; flex-direction: column; gap: 0.8rem; text-align: left; background: rgba(0,0,0,0.2); padding: 1.5rem; border-radius: 16px;">
                            <div style="font-size: 0.85rem;">
                                <span style="color: var(--text-muted); display: block; margin-bottom: 2px;">LRN</span>
                                <span style="font-weight: 600;"><?php echo htmlspecialchars($student_lrn); ?></span>
                            </div>
                            <div style="font-size: 0.85rem;">
                                <span style="color: var(--text-muted); display: block; margin-bottom: 2px;">Grade & Section</span>
                                <span style="font-weight: 600;"><?php echo htmlspecialchars($student_grade . ' - ' . $student_section); ?></span>
                            </div>
                            <div style="font-size: 0.85rem;">
                                <span style="color: var(--text-muted); display: block; margin-bottom: 2px;">Strand</span>
                                <span style="font-weight: 600;"><?php echo htmlspecialchars($student_strand); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="glass-card" style="padding: 1.5rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, transparent 100%);">
                        <h3 style="margin-bottom: 1rem; color: var(--success-color);"><i class="fas fa-chart-line" style="margin-right: 8px;"></i> Your Stats</h3>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; text-align: center;">
                            <div>
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-color);"><?php echo $total_submissions; ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Total Submissions</div>
                            </div>
                            <div>
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--success-color);"><?php echo $graded_count; ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Graded</div>
                            </div>
                            <div>
                                <div style="font-size: 1.5rem; font-weight: 700; color: var(--warning-color);"><?php echo $pending_count; ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Pending Review</div>
                            </div>
                            <div>
                                <div style="font-size: 1.5rem; font-weight: 700; color: #a259ff;"><?php echo $total_submissions > 0 ? round(($graded_count / $total_submissions) * 100) : 0; ?>%</div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">Graded Rate</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <script src="../assets/js/system_loader.js?v=20260924-loader4"></script>
    <script src="../assets/js/responsive_ui.js"></script>
    <script src="../assets/js/pwa.js"></script>
</body>
</html>

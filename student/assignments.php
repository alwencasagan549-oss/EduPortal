<?php
/**
 * Student Portal: My Assignments
 * Displays assignments selectively targeted to the student's Grade Level and Section.
 */

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
$student_grade = $_SESSION['user_grade'];
$student_section = $_SESSION['user_section'];

$student_strand = $_SESSION['user_strand'] ?? 'Academic';

// Fetch assignments matching this student's group
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, subject, title, description, file_path, teacher_name, created_at FROM posted_assignments WHERE grade_level = ? AND section = ? AND strand = ? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$student_grade, $student_section, $student_strand]);
$assignments = $stmt->get_result()->fetch_all();

$submittedAssignmentIds = [];
try {
    $submittedStmt = $conn->prepare(
        'SELECT assignment_id
         FROM submissions
         WHERE student_id = ? AND assignment_id IS NOT NULL'
    );
    $submittedStmt->execute([$student_id]);
    $submittedAssignments = $submittedStmt->get_result()->fetch_all();
    if (is_array($submittedAssignments)) {
        foreach ($submittedAssignments as $submittedAssignment) {
            $submittedAssignmentId = (int) ($submittedAssignment['assignment_id'] ?? 0);
            if ($submittedAssignmentId > 0) {
                $submittedAssignmentIds[$submittedAssignmentId] = true;
            }
        }
    }
} catch (Throwable $exception) {
    error_log('EduPortal submitted assignment lookup failed: ' . $exception->getMessage());
}

require_once __DIR__ . '/nav.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assignments | EduPortal Student</title>
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
        <?php renderStudentNav('assignments'); ?>

        <!-- Main Content -->
        <main class="main-content" id="main-content">
            <header class="top-bar">
                <button class="menu-toggle" type="button" aria-label="Open navigation" aria-controls="student-sidebar" aria-expanded="false">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>
                <div class="page-title">
                    <h1>Selective Assignments</h1>
                    <p>Academic materials for <strong><?php echo htmlspecialchars($student_grade . ' - ' . ($student_strand ?? 'Academic') . ' | ' . $student_section); ?></strong></p>
                </div>
            </header>

            <div class="assignment-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
                <?php if (empty($assignments)): ?>
                    <div class="glass-card" style="grid-column: 1 / -1; padding: 5rem; text-align: center;">
                        <i class="fas fa-inbox" style="font-size: 4rem; color: var(--text-muted); opacity: 0.2; margin-bottom: 2rem;"></i>
                        <h2>No New Assignments</h2>
                        <p style="color: var(--text-muted);">Your teachers haven't posted any materials for your group yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($assignments as $a): ?>
                        <?php
                        $assignmentId = (int) ($a['id'] ?? 0);
                        $isSubmitted = isset($submittedAssignmentIds[$assignmentId]);
                        ?>
                        <div class="glass-card animate-fade-up" style="padding: 2rem; display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                                    <span class="premium-badge badge-blue"><?php echo htmlspecialchars($a['subject']); ?></span>
                                    <span style="font-size: 0.75rem; color: var(--text-muted);"><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($a['created_at'])); ?></span>
                                </div>
                                <h3 style="font-size: 1.25rem; margin-bottom: 1rem;"><?php echo htmlspecialchars($a['title']); ?></h3>
                                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 2rem; line-height: 1.6;">
                                    <?php echo nl2br(htmlspecialchars($a['description'])); ?>
                                </p>
                            </div>

                            <div style="border-top: 1px solid var(--glass-border); padding-top: 1.5rem; margin-top: auto;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--bg-sidebar); display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-user-tie" style="font-size: 0.8rem; color: var(--primary-color);"></i>
                                        </div>
                                        <div style="font-size: 0.85rem;">
                                            <span style="display: block; font-weight: 600;"><?php echo htmlspecialchars($a['teacher_name']); ?></span>
                                            <span style="font-size: 0.75rem; color: var(--text-muted);">Faculty Member</span>
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                        <?php if (!$isSubmitted): ?>
                                            <a href="../controllers/download_assignment.php?id=<?php echo $assignmentId; ?>" class="premium-btn premium-btn-primary" style="padding: 0.6rem 1rem; font-size: 0.85rem;">
                                                <i class="fas fa-download"></i> Get Copy
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($isSubmitted): ?>
                                            <span class="premium-badge badge-green" style="display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.6rem 0.8rem;" role="status" aria-label="Submitted">
                                                <span aria-hidden="true" style="width: 0.45rem; height: 0.45rem; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.14);"></span>
                                                Submitted
                                            </span>
                                        <?php else: ?>
                                            <a href="dashboard.php?assignment_id=<?php echo $assignmentId; ?>&amp;subject=<?php echo rawurlencode((string) $a['subject']); ?>#submissionModal" class="premium-btn premium-btn-outline" style="padding: 0.6rem 1rem; font-size: 0.85rem;" aria-label="Submit work for <?php echo htmlspecialchars((string) $a['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-paper-plane"></i> Submit
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="../assets/js/system_loader.js?v=20260924-loader4"></script>
    <script src="../assets/js/responsive_ui.js"></script>
    <script src="../assets/js/pwa.js"></script>
</body>

</html>


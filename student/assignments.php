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
    header('Location: index.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'];
$student_grade = $_SESSION['user_grade'];
$student_section = $_SESSION['user_section'];

$student_strand = $_SESSION['user_strand'] ?? 'Academic';

// Fetch assignments matching this student's group
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT * FROM posted_assignments WHERE grade_level = ? AND section = ? AND strand = ? ORDER BY created_at DESC");
$stmt->execute([$student_grade, $student_section, $student_strand]);
$assignments = $stmt->get_result()->fetch_all();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assignments | EduPortal Student</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <div class="layout-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="sidebar-brand">
                    Edu<span>Portal</span>
                </div>
            </div>

            <nav class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" class="menu-link">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>
                <li class="menu-item">
                    <a href="assignments.php" class="menu-link active">
                        <i class="fas fa-book-open"></i> New Assignments
                    </a>
                </li>
            </nav>

            <div class="sidebar-footer">
                <div class="user-snippet">
                    <div class="avatar-small">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="user-snippet-info">
                        <div class="user-name"><?php echo htmlspecialchars($student_name); ?></div>
                        <div class="user-status"><i class="fas fa-circle" style="font-size: 0.5rem"></i> Online</div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-link">
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
                                    <a href="<?php echo htmlspecialchars($a['file_path']); ?>" download class="premium-btn premium-btn-primary" style="padding: 0.6rem 1rem; font-size: 0.85rem;">
                                        <i class="fas fa-download"></i> Get Copy
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script src="../assets/js/system_loader.js"></script>
    <script src="../assets/js/responsive_ui.js"></script>
</body>

</html>

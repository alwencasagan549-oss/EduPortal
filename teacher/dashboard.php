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
$teacher_subject = $_SESSION['user_subject'];

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grading'])) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        die('Invalid security token.');
    }
    $submission_id = intval($_POST['submission_id']);
    $marks = trim($_POST['marks'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE submissions SET marks = ?, remarks = ? WHERE id = ? AND subject = ?");
    $stmt->execute([$marks, $remarks, $submission_id, $teacher_subject]);

    header('Location: dashboard.php?updated=1');
    exit();
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_submission'])) {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        die('Invalid security token.');
    }
    $conn = getDBConnection();
    $stmt = $conn->prepare("DELETE FROM submissions WHERE id = ? AND subject = ?");
    $stmt->execute([$_POST['submission_id'], $teacher_subject]);

    header('Location: dashboard.php?deleted=1');
    exit();
}

// Filter parameters
$filter_grade = $_GET['grade'] ?? '';
$filter_section = trim($_GET['section'] ?? '');
$filter_strand = $_GET['strand'] ?? '';

// Get submissions for this teacher's subject with student details
$conn = getDBConnection();

$query = "SELECT s.*, st.name as student_name, st.grade_level, st.section, st.strand
          FROM submissions s
          LEFT JOIN students st ON s.student_id = st.id
          WHERE s.subject = ?";

$params = [$teacher_subject];

if (!empty($filter_grade)) {
    $query .= " AND st.grade_level = ?";
    $params[] = $filter_grade;
}

if (!empty($filter_strand)) {
    $query .= " AND st.strand = ?";
    $params[] = $filter_strand;
}

if (!empty($filter_section)) {
    $query .= " AND st.section LIKE ?";
    $params[] = "%$filter_section%";
}

$query .= " ORDER BY s.submitted_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$result = $stmt->get_result();
$submissions = $result->fetch_all();

// Statistics
$total_submissions = count($submissions);
$reviewed_count = count(array_filter($submissions, fn($s) => !empty($s['marks'])));
$pending_count = $total_submissions - $reviewed_count;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | EduPortal LMS</title>
    <link rel="icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="../assets/script.js" defer></script>
</head>

<body>
    <div class="layout-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="sidebar-brand">
                    Edu<span>Portal</span>
                </div>
            </div>

            <nav class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" class="menu-link active" onclick="EduPortal.navigate('Dashboard', 'Loading dashboard...', this)">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>
                <li class="menu-item">
                    <a href="post_assignment.php" class="menu-link" onclick="EduPortal.navigate('Post Assignment', 'Preparing assignment portal...', this)">
                        <i class="fas fa-upload"></i> Post Assignment
                    </a>
                </li>
                <li class="menu-item">
                    <a href="profile.php" class="menu-link" onclick="EduPortal.navigate('Profile', 'Loading profile settings...', this)">
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
                        <div class="user-name"><?php echo htmlspecialchars($teacher_name); ?></div>
                        <div class="user-status"><i class="fas fa-circle" style="font-size: 0.5rem"></i> Online</div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-link" onclick="return EduPortal.confirmLogout(this)">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="top-bar">
                <button class="menu-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Teacher Portal</h1>
                    <p>Managing <strong><?php echo htmlspecialchars($teacher_subject); ?></strong> assignments.</p>
                </div>

                <div class="top-bar-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search student files...">
                    </div>
                    <button class="icon-button">
                        <i class="fas fa-bell"></i>
                    </button>
                </div>
            </header>

            <!-- Status messages -->
            <?php if (isset($_GET['updated'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><strong>Graded!</strong> Student marks and remarks have been saved.</div>
                </div>
            <?php elseif (isset($_GET['deleted'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div><strong>Deleted!</strong> Submission has been removed.</div>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Total Submissions</span>
                        <span class="stat-value"><?php echo $total_submissions; ?></span>
                        <div class="stat-trend up">
                            <i class="fas fa-file-invoice"></i> Received
                        </div>
                    </div>
                </div>
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Graded</span>
                        <span class="stat-value"><?php echo $reviewed_count; ?></span>
                        <div class="stat-trend up">
                            <i class="fas fa-check-double"></i> Completed
                        </div>
                    </div>
                </div>
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Pending Review</span>
                        <span class="stat-value"><?php echo $pending_count; ?></span>
                        <div class="stat-trend <?php echo $pending_count > 0 ? 'down' : 'up'; ?>">
                            <i class="fas fa-clock"></i> Action Required
                        </div>
                    </div>
                </div>
                <div class="glass-card" style="background: var(--bg-sidebar); border: 2px solid var(--primary-color);">
                    <div class="stat-item-premium">
                        <span class="stat-label" style="color: var(--primary-color);">Export Data</span>
                        <div style="margin-top: 10px;">
                            <a href="../controllers/download_all.php?teacher=<?php echo $teacher_id; ?>"
                                class="premium-btn premium-btn-primary" style="width: 100%;">
                                <i class="fas fa-file-zipper"></i> Download ZIP
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Table Section -->
            <div class="table-container">
                <div class="table-header"
                    style="display: flex; flex-direction: column; gap: 1.5rem; align-items: stretch;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h2 style="margin: 0;"><i class="fas fa-list"
                                style="margin-right: 10px; color: var(--primary-color)"></i> Student Submissions</h2>
                    </div>

                    <!-- Filter Bar -->
                    <form method="GET" class="glass-card responsive-grid-stack" data-loader="true"
                        style="padding: 1rem; display: flex; gap: 1rem; align-items: flex-end; border-radius: 16px; background: rgba(255,255,255,0.02); flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 200px;">
                            <label
                                style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; color: var(--text-muted);">Grade
                                Level</label>
                            <select name="grade" class="premium-input" style="padding: 0.6rem 1rem;">
                                <option value="">All Grades</option>
                                <option value="Grade 7" <?php echo ($filter_grade == 'Grade 7') ? 'selected' : ''; ?>>
                                    Grade 7</option>
                                <option value="Grade 8" <?php echo ($filter_grade == 'Grade 8') ? 'selected' : ''; ?>>
                                    Grade 8</option>
                                <option value="Grade 9" <?php echo ($filter_grade == 'Grade 9') ? 'selected' : ''; ?>>
                                    Grade 9</option>
                                <option value="Grade 10" <?php echo ($filter_grade == 'Grade 10') ? 'selected' : ''; ?>>
                                    Grade 10</option>
                                <option value="Grade 11" <?php echo ($filter_grade == 'Grade 11') ? 'selected' : ''; ?>>
                                    Grade 11</option>
                                <option value="Grade 12" <?php echo ($filter_grade == 'Grade 12') ? 'selected' : ''; ?>>
                                    Grade 12</option>
                            </select>
                        </div>
                        <div style="flex: 1; min-width: 200px;">
                            <label
                                style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; color: var(--text-muted);">Section</label>
                            <input type="text" name="section" value="<?php echo htmlspecialchars($filter_section); ?>"
                                placeholder="e.g. STEM-A" class="premium-input" style="padding: 0.6rem 1rem;">
                        </div>
                        <div style="flex: 1; min-width: 200px;">
                            <label
                                style="display: block; margin-bottom: 0.5rem; font-size: 0.8rem; color: var(--text-muted);">Strand</label>
                            <select name="strand" class="premium-input" style="padding: 0.6rem 1rem;">
                                <option value="">All Strands</option>
                                <option value="Academic" <?php echo ($filter_strand == 'Academic') ? 'selected' : ''; ?>>Academic</option>
                                <option value="Tech-pro" <?php echo ($filter_strand == 'Tech-pro') ? 'selected' : ''; ?>>Tech-pro</option>
                            </select>
                        </div>
                        <button type="submit" class="premium-btn premium-btn-primary" style="padding: 0.6rem 1.5rem; white-space: nowrap;">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <a href="dashboard.php" class="premium-btn premium-btn-outline" style="padding: 0.6rem 1rem;">
                            <i class="fas fa-undo"></i>
                        </a>
                    </form>
                </div>

                <div style="overflow-x: auto;">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>File Name</th>
                                <th>Date Submitted</th>
                                <th>Marks</th>
                                <th>Feedback</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($submissions)): ?>
                                <tr>
                                    <td colspan="7">
                                        <div style="text-align: center; padding: 4rem; color: var(--text-muted);">
                                            <i class="fas fa-inbox"
                                                style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.2;"></i>
                                            <p>No assignments submitted yet for this subject.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($submissions as $submission): ?>
                                    <tr>
                                        <td><span
                                                class="premium-badge badge-blue">#<?php echo htmlspecialchars($submission['id']); ?></span>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <i class="fas fa-user-graduate" style="color: var(--text-muted)"></i>
                                                <div>
                                                    <span
                                                        style="font-weight: 500; display: block;"><?php echo htmlspecialchars($submission['student_name'] ?? 'Unknown'); ?></span>
                                                    <span
                                                        style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars(($submission['grade_level'] ?? 'N/A') . ' | ' . ($submission['section'] ?? 'N/A')); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 10px;">
                                                <a href="../controllers/download.php?id=<?php echo $submission['id']; ?>"
                                                    class="premium-btn premium-btn-outline"
                                                    style="padding: 0.6rem 1.2rem; font-size: 0.85rem;">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                            </div>
                                        </td>
                                        <td style="font-size: 0.85rem; color: var(--text-muted);">
                                            <div style="font-weight: 500; color: var(--text-main);">
                                                <?php echo date('M d, Y', strtotime($submission['submitted_at'])); ?></div>
                                            <div style="font-size: 0.75rem; opacity: 0.7;"><i class="fas fa-clock"
                                                    style="font-size: 0.7rem;"></i>
                                                <?php echo date('h:i A', strtotime($submission['submitted_at'])); ?></div>
                                        </td>
                                        <td>
                                            <form method="POST" id="grade-form-<?php echo $submission['id']; ?>"
                                                data-loader="true">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="submission_id"
                                                    value="<?php echo $submission['id']; ?>">
                                                <input type="text" name="marks"
                                                    value="<?php echo htmlspecialchars($submission['marks'] ?? ''); ?>"
                                                    placeholder="Grade" class="premium-input" style="width: 80px;">
                                        </td>
                                        <td>
                                            <input type="text" name="remarks"
                                                value="<?php echo htmlspecialchars($submission['remarks'] ?? ''); ?>"
                                                placeholder="Add comments..." class="premium-input">
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 8px;">
                                                <button type="submit" name="update_grading"
                                                    form="grade-form-<?php echo $submission['id']; ?>"
                                                    class="premium-btn premium-btn-primary"
                                                    style="padding: 0.5rem; font-size: 0.8rem;">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <form method="POST" onsubmit="return confirm('Delete this record?')" data-loader="true">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="submission_id"
                                                        value="<?php echo $submission['id']; ?>">
                                                    <button type="submit" name="delete_submission"
                                                        class="premium-btn premium-btn-outline"
                                                        style="padding: 0.5rem; font-size: 0.8rem; color: var(--danger-color); border-color: rgba(239, 68, 68, 0.2);">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-hint" style="margin-top: 5px;">
                    <i class="fas fa-hand-point-left"></i> Swipe left for more info...
                </div>
            </div>
            <footer
                style="margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center; color: var(--text-muted); font-size: 0.85rem;">
                <p>&copy; <?php echo date('Y'); ?> EduPortal LMS. All rights reserved.</p>
                <div style="display: flex; gap: 1.5rem; align-items: center;">
                    <span style="opacity: 0.7;">Web Developer: <strong id="_sys_v_auth"><a href="https://casagan.vercel.app/" target="_blank" style="color: inherit; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwen T. Casagan</a></strong></span>
                    <i class="fas fa-shield-halved" style="color: var(--success-color); opacity: 0.5;"
                        title="EDU-Shield Certified"></i>
                </div>
            </footer>
        </main>
    </div>
    <script src="../assets/js/system_loader.js"></script>
    <script src="../assets/js/responsive_ui.js"></script>
</body>

</html>
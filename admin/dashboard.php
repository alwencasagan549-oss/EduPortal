<?php
require_once '../config/database.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE submissions SET marks = ?, remarks = ? WHERE id = ?");
    $stmt->bind_param("ssi", $_POST['marks'], $_POST['remarks'], $_POST['id']);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    
    header('Location: dashboard.php?updated=1');
    exit();
}

// Get all submissions with student details
$conn = getDBConnection();
$result = $conn->query("SELECT s.*, st.grade_level, st.section 
                       FROM submissions s 
                       LEFT JOIN students st ON s.student_id = st.id 
                       ORDER BY s.submitted_at DESC");
$submissions = $result->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | EduPortal LMS</title>
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
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="sidebar-brand">
                    Edu<span>Portal</span>
                </div>
            </div>
            
            <nav class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" class="menu-link active">
                        <i class="fas fa-grid-2"></i> Dashboard
                    </a>
                </li>
                <li class="menu-item">
                    <a href="download_all.php" class="menu-link">
                        <i class="fas fa-file-export"></i> Mass Export
                    </a>
                </li>
            </nav>
            
            <div class="sidebar-footer">
                <div class="user-snippet">
                    <div class="avatar-small">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="user-snippet-info">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['admin_username']); ?></div>
                        <div class="user-status"><i class="fas fa-circle" style="font-size: 0.5rem"></i> Active</div>
                    </div>
                </div>
                <a href="logout.php" class="logout-link">
                    <i class="fas fa-power-off"></i> Logout
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <header class="top-bar">
                <div class="page-title">
                    <h1>Dashboard</h1>
                    <p>Welcome back, system administrator.</p>
                </div>
                
                <div class="top-bar-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search submissions...">
                    </div>
                    <button class="icon-button">
                        <i class="fas fa-bell"></i>
                    </button>
                    <button class="icon-button">
                        <i class="fas fa-moon"></i>
                    </button>
                </div>
            </header>

            <!-- Status messages -->
            <?php if (isset($_GET['updated'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-circle-check"></i> 
                    <div><strong>Success!</strong> Marks and remarks have been updated.</div>
                </div>
            <?php elseif (isset($_GET['deleted'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-trash-can"></i> 
                    <div><strong>Removed!</strong> Submission record deleted.</div>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Total Submissions</span>
                        <span class="stat-value"><?php echo count($submissions); ?></span>
                        <div class="stat-trend up">
                            <i class="fas fa-arrow-up"></i> 12% increase
                        </div>
                    </div>
                </div>
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Graded Assignments</span>
                        <?php $graded = count(array_filter($submissions, fn($s) => !empty($s['marks']))); ?>
                        <span class="stat-value"><?php echo $graded; ?></span>
                        <div class="stat-trend up">
                            <i class="fas fa-arrow-up"></i> 8% this week
                        </div>
                    </div>
                </div>
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Pending Review</span>
                        <span class="stat-value"><?php echo count($submissions) - $graded; ?></span>
                        <div class="stat-trend down">
                            <i class="fas fa-clock"></i> Action required
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Table Section -->
            <div class="table-container">
                <div class="table-header">
                    <h2><i class="fas fa-list-check" style="margin-right: 10px; color: var(--primary-color)"></i> Recent Submissions</h2>
                    <a href="download_all.php" class="premium-btn premium-btn-primary">
                        <i class="fas fa-download"></i> Download All (ZIP)
                    </a>
                </div>
                
                <div style="overflow-x: auto;">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Submission ID</th>
                                <th>Student Name</th>
                                <th>Class Info</th>
                                <th>Subject</th>
                                <th>Original File</th>
                                <th>Assign Grade</th>
                                <th>Feedback / Remarks</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($submissions)): ?>
                                <tr>
                                    <td colspan="8">
                                        <div style="text-align: center; padding: 4rem; color: var(--text-muted);">
                                            <i class="fas fa-folder-open" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.2;"></i>
                                            <p>No active submissions found in the database.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($submissions as $submission): ?>
                                <tr>
                                    <td><span class="premium-badge badge-blue">#<?php echo $submission['id']; ?></span></td>
                                    <td style="font-weight: 500; font-size: 0.95rem;">
                                        <?php echo htmlspecialchars($submission['student_name'] ?? 'Unknown'); ?>
                                    </td>
                                    <td style="font-size: 0.85rem; color: var(--text-muted);">
                                        <?php echo htmlspecialchars(($submission['grade_level'] ?? 'N/A') . ' | ' . ($submission['section'] ?? 'N/A')); ?>
                                    </td>
                                    <td>
                                        <span class="premium-badge badge-yellow"><?php echo htmlspecialchars($submission['subject']); ?></span>
                                    </td>
                                    <td>
                                        <a href="download.php?id=<?php echo $submission['id']; ?>" class="premium-btn premium-btn-outline" style="padding: 0.5rem 0.8rem; font-size: 0.85rem;">
                                            <i class="fas fa-file-pdf"></i> View File
                                        </a>
                                    </td>
                                    <td>
                                        <form method="POST" id="form-<?php echo $submission['id']; ?>" data-loader="true">
                                            <input type="hidden" name="id" value="<?php echo $submission['id']; ?>">
                                            <input type="text" name="marks" 
                                                   value="<?php echo htmlspecialchars($submission['marks'] ?? ''); ?>"
                                                   placeholder="Score" class="premium-input" style="width: 100px;">
                                    </td>
                                    <td>
                                            <input type="text" name="remarks"
                                                   value="<?php echo htmlspecialchars($submission['remarks'] ?? ''); ?>"
                                                   placeholder="Add feedback..." class="premium-input">
                                    </td>
                                    <td style="font-size: 0.85rem; color: var(--text-muted);">
                                        <div style="font-weight: 500; color: var(--text-main);"><?php echo date('M d, Y', strtotime($submission['submitted_at'])); ?></div>
                                        <div style="font-size: 0.75rem; opacity: 0.7;"><i class="fas fa-clock" style="font-size: 0.7rem;"></i> <?php echo date('h:i A', strtotime($submission['submitted_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 8px;">
                                            <button type="submit" name="update" form="form-<?php echo $submission['id']; ?>" class="premium-btn premium-btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                                                <i class="fas fa-save"></i>
                                            </button>
                                            <a href="delete.php?id=<?php echo $submission['id']; ?>" 
                                               class="premium-btn premium-btn-outline"
                                               style="padding: 0.5rem 1rem; font-size: 0.85rem; color: var(--danger-color); border-color: rgba(239, 68, 68, 0.2);"
                                               onclick="return confirm('Are you sure you want to delete this submission?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
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
            <footer style="margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center; color: var(--text-muted); font-size: 0.85rem;">
                <p>&copy; <?php echo date('Y'); ?> EduPortal LMS. All rights reserved.</p>
                <div style="display: flex; gap: 1.5rem; align-items: center;">
                    <span style="opacity: 0.7;">Web Developer: <strong id="_sys_v_auth"><a href="https://casagan.vercel.app/" target="_blank" style="color: inherit; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwen T. Casagan</a></strong></span>
                    <i class="fas fa-shield-halved" style="color: var(--success-color); opacity: 0.5;" title="EDU-Shield Certified"></i>
                </div>
            </footer>
        </main>
    </div>
    <script src="../assets/js/system_loader.js"></script>
</body>
</html>
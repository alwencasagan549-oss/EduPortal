<?php
require_once '../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'];
$student_lrn = $_SESSION['user_lrn'];

// Get student submissions
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, subject, file_path, marks, remarks, submitted_at FROM submissions WHERE student_id = ? ORDER BY submitted_at DESC");
$stmt->execute([$student_id]);
$submissions = $stmt->get_result()->fetch_all();

// Get broadcasted assignments for this student's group
$student_grade = $_SESSION['user_grade'] ?? '';
$student_section = $_SESSION['user_section'] ?? '';
$student_strand = $_SESSION['user_strand'] ?? 'Academic';
$stmt2 = $conn->prepare("SELECT id, subject, title, description, file_path, teacher_name, created_at FROM posted_assignments WHERE grade_level = ? AND section = ? AND strand = ? ORDER BY created_at DESC");
$stmt2->execute([$student_grade, $student_section, $student_strand]);
$broadcasted = $stmt2->get_result()->fetch_all();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | EduPortal LMS</title>
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
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="sidebar-brand">
                    Edu<span>Portal</span>
                </div>
            </div>
            
            <nav class="sidebar-menu">
                <li class="menu-item">
                    <a href="dashboard.php" class="menu-link active" onclick="EduPortal.navigate('Student Hub', 'Loading your dashboard...', this)">
                        <i class="fas fa-house"></i> Home
                    </a>
                </li>
                <li class="menu-item">
                    <a href="assignments.php" class="menu-link" onclick="EduPortal.navigate('Assignments', 'Loading assignments...', this)">
                        <i class="fas fa-file-arrow-down"></i> New Assignments
                    </a>
                </li>
            </nav>
            
            <div class="sidebar-footer">
                <div class="user-snippet">
                    <div class="avatar-small">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="user-snippet-info">
                        <div class="user-name"><?php echo htmlspecialchars($student_name); ?></div>
                        <div class="user-status"><i class="fas fa-circle" style="font-size: 0.5rem"></i> Student</div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-link" onclick="return EduPortal.confirmLogout(this)">
                    <i class="fas fa-right-from-bracket"></i> Logout
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
                    <h1>Student Hub</h1>
                    <p>Welcome back, <strong><?php echo htmlspecialchars($_SESSION['user_grade'] ?? 'Grade 11'); ?> - <?php echo htmlspecialchars($_SESSION['user_strand'] ?? 'Academic'); ?> | <?php echo htmlspecialchars($_SESSION['user_section'] ?? 'Unassigned'); ?></strong></p>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">LRN: <?php echo htmlspecialchars($_SESSION['user_lrn'] ?? ''); ?></p>
                </div>
                
                <div class="top-bar-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search subjects...">
                    </div>
                    <button class="icon-button">
                        <i class="fas fa-bell"></i>
                    </button>
                </div>
            </header>

            <!-- Success/Error Messages -->
            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-circle-check"></i> 
                    <div><strong>Submitted!</strong> Your assignment has been uploaded successfully.</div>
                </div>
            <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-circle-xmark"></i> 
                    <div><strong>Error:</strong> <?php echo htmlspecialchars(urldecode($_GET['error'])); ?></div>
                </div>
            <?php endif; ?>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Submissions</span>
                        <span class="stat-value"><?php echo count($submissions); ?></span>
                        <div class="stat-trend up">
                            <i class="fas fa-file-circle-check"></i> Total Uploads
                        </div>
                    </div>
                </div>
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Graded</span>
                        <span class="stat-value"><?php echo count(array_filter($submissions, fn($s) => !empty($s['marks']))); ?></span>
                        <div class="stat-trend up">
                            <i class="fas fa-star"></i> Reviewed
                        </div>
                    </div>
                </div>
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Await Review</span>
                        <span class="stat-value"><?php echo count(array_filter($submissions, fn($s) => empty($s['marks']))); ?></span>
                        <div class="stat-trend down">
                            <i class="fas fa-hourglass-half"></i> Processing
                        </div>
                    </div>
                </div>
            </div>

            <!-- Broadcasted Assignments -->
            <?php if (!empty($broadcasted)): ?>
            <div class="table-container">
                <div class="table-header">
                    <h2><i class="fas fa-bullhorn" style="margin-right: 10px; color: var(--primary-color)"></i> Teacher Broadcasts</h2>
                    <span class="premium-badge badge-blue"><?php echo count($broadcasted); ?> New</span>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem;">
                    <?php foreach ($broadcasted as $a): ?>
                    <div class="glass-card" style="padding: 1.5rem; border-left: 3px solid var(--primary-color);">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                            <span class="premium-badge badge-blue"><?php echo htmlspecialchars($a['subject']); ?></span>
                            <span style="font-size: 0.75rem; color: var(--text-muted);"><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($a['created_at'])); ?></span>
                        </div>
                        <h3 style="font-size: 1.1rem; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($a['title']); ?></h3>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1rem; line-height: 1.5;">
                            <?php echo nl2br(htmlspecialchars($a['description'])); ?>
                        </p>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 1rem; border-top: 1px solid var(--glass-border);">
                            <span style="font-size: 0.8rem; color: var(--text-muted);"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($a['teacher_name']); ?></span>
                            <a href="<?php echo htmlspecialchars($a['file_path']); ?>" download class="premium-btn premium-btn-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem;">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem;">
                <!-- Submit Form -->
                <div class="glass-card">
                    <h2 style="margin-bottom: 1.5rem; font-size: 1.25rem;"><i class="fas fa-cloud-arrow-up" style="color: var(--primary-color); margin-right: 10px;"></i> New Assignment</h2>
                    
                    <form action="../controllers/submit.php" method="POST" enctype="multipart/form-data" onsubmit="return validateForm()" data-loader="true">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <div style="margin-bottom: 1.5rem;">
                            <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Subject Name</label>
                            <input type="text" id="subject" name="subject" required 
                                   placeholder="e.g., Mathematics" class="premium-input">
                        </div>
                        
                        <div style="margin-bottom: 2rem;">
                            <label style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Assignment File</label>
                            <div style="border: 2px dashed var(--glass-border); border-radius: 16px; padding: 2rem; text-align: center; cursor: pointer; transition: all 0.2s;" 
                                 onclick="document.getElementById('assignment').click()"
                                 id="dropZone">
                                <input type="file" id="assignment" name="assignment" 
                                       style="display: none;" accept=".pdf,.doc,.docx" 
                                       onchange="updateFileName(this)">
                                <div id="fileInfo">
                                    <i class="fas fa-file-arrow-up" style="font-size: 2.5rem; color: var(--primary-color); margin-bottom: 1rem; opacity: 0.5;"></i>
                                    <p style="font-size: 0.9rem; font-weight: 500;">Select document</p>
                                    <p style="font-size: 0.75rem; color: var(--text-muted);">PDF or DOCX (Max 10MB)</p>
                                </div>
                                <div id="fileName" style="display: none; font-weight: 600; color: var(--text-main);"></div>
                            </div>
                        </div>
                        
                        <button type="submit" class="premium-btn premium-btn-primary" style="width: 100%;">
                            <i class="fas fa-paper-plane"></i> Submit Now
                        </button>
                    </form>
                </div>

                <!-- History Table -->
                <div class="table-container" style="margin-top: 0; flex: 1; min-width: 0;">
                    <div class="table-header">
                        <h2><i class="fas fa-history" style="margin-right: 10px; color: var(--primary-color)"></i> Recent Activity</h2>
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table class="premium-table">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>File</th>
                                    <th>Grade</th>
                                    <th>Feedback</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($submissions)): ?>
                                    <tr>
                                        <td colspan="5">
                                            <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                                                <i class="fas fa-folder-open" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.2;"></i>
                                                <p>No submission records found.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($submissions as $submission): ?>
                                    <tr>
                                        <td><span class="premium-badge badge-yellow"><?php echo htmlspecialchars($submission['subject']); ?></span></td>
                                        <td>
                                            <a href="../controllers/download.php?id=<?php echo htmlspecialchars($submission['id']); ?>" class="premium-btn premium-btn-outline" style="padding: 0.4rem 0.6rem; font-size: 0.75rem;">
                                                <i class="fas fa-file-lines"></i> View
                                            </a>
                                        </td>
                                        <td>
                                            <?php if (!empty($submission['marks'])): ?>
                                                <span class="premium-badge badge-green" style="font-size: 0.85rem;">
                                                    <?php echo htmlspecialchars($submission['marks']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="premium-badge" style="background: rgba(255,255,255,0.05); color: var(--text-muted);">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($submission['remarks'])): ?>
                                                <div style="font-size: 0.85rem; padding: 0.5rem; border-radius: 8px; background: rgba(255,255,255,0.03); border-left: 3px solid var(--primary-color);">
                                                    <?php echo htmlspecialchars($submission['remarks']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="font-size: 0.8rem; color: var(--text-muted); font-style: italic;">No feedback</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size: 0.8rem; color: var(--text-muted);">
                                            <div style="font-weight: 500; color: var(--text-main);"><?php echo date('M d, Y', strtotime($submission['submitted_at'])); ?></div>
                                            <div style="font-size: 0.72rem; opacity: 0.7;"><i class="fas fa-clock" style="font-size: 0.65rem;"></i> <?php echo date('h:i A', strtotime($submission['submitted_at'])); ?></div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="mobile-hint">
                        <i class="fas fa-hand-point-left"></i> Swipe left for more info...
                    </div>
                </div>
            </div>
            <footer style="margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center; color: var(--text-muted); font-size: 0.85rem;">
                <p>&copy; <?php echo date('Y'); ?> EduPortal LMS. All rights reserved.</p>
                <div style="display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap; justify-content: center;">
                    <span style="opacity: 0.7;">Web Developer: <strong id="_sys_v_auth"><a href="https://casagan.vercel.app/" target="_blank" style="color: inherit; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwen T. Casagan</a></strong></span>
                    <i class="fas fa-shield-halved" style="color: var(--success-color); opacity: 0.5;" title="EDU-Shield Certified"></i>
                </div>
            </footer>
        </main>
    </div>
    
    <script>
    function updateFileName(input) {
        if (input.files && input.files[0]) {
            const fileInfo = document.getElementById('fileInfo');
            const fileNameContainer = document.getElementById('fileName');
            const dropZone = document.getElementById('dropZone');
            
            fileInfo.style.display = 'none';
            fileNameContainer.style.display = 'block';
            
            // Auth Shield: Clear and safely set text content (XSS Protection)
            fileNameContainer.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success-color); margin-right: 8px;"></i>';
            const textNode = document.createTextNode(input.files[0].name);
            fileNameContainer.appendChild(textNode);
            
            dropZone.style.borderColor = 'var(--success-color)';
        }
    }

    function validateForm() {
        const fileInput = document.getElementById('assignment');
        const subjectInput = document.getElementById('subject');
        
        if (!subjectInput.value.trim()) {
            alert('Please specify the subject');
            return false;
        }
        if (!fileInput.files || !fileInput.files[0]) {
            alert('Please attach your assignment file');
            return false;
        }
        const maxSize = 10 * 1024 * 1024;
        if (fileInput.files[0].size > maxSize) {
            alert('File size exceeds 10MB limit');
            return false;
        }
        return true;
    }
    </script>
    <script src="../assets/js/system_loader.js"></script>
    <script src="../assets/js/responsive_ui.js"></script>
</body>
</html>
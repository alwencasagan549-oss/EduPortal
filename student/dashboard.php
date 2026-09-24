<?php
require_once '../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: /session_expired.php');
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
    <style>
        .notification-item {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--glass-border);
            cursor: pointer;
            transition: background 0.15s;
        }
        .notification-item:hover {
            background: rgba(78,115,223,0.04);
        }
        .notification-item.unread {
            border-left: 3px solid var(--primary-color);
        }
        .notification-item.read {
            opacity: 0.65;
        }
        .notification-title {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-main);
            margin-bottom: 2px;
        }
        .notification-message {
            font-size: 0.78rem;
            color: var(--text-muted);
            line-height: 1.4;
        }
        .notification-time {
            font-size: 0.7rem;
            color: var(--text-muted);
            opacity: 0.7;
            margin-top: 4px;
        }
    </style>
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
                <form method="POST" action="../logout.php" style="display:inline;" onsubmit="return EduPortal.confirmLogout(this)">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <button type="submit" class="logout-link">
                        <i class="fas fa-right-from-bracket"></i> Logout
                    </button>
                </form>
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
                    <div style="position: relative;">
                        <button class="icon-button" id="notificationBell" style="position: relative;">
                            <i class="fas fa-bell"></i>
                            <span id="notificationBadge" style="display: none; position: absolute; top: -4px; right: -4px; background: var(--danger-color); color: white; font-size: 0.65rem; font-weight: 700; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">0</span>
                        </button>
                        <div id="notificationPanel" style="display: none; position: absolute; top: 48px; right: 0; width: 360px; max-height: 480px; background: var(--bg-card); border: 1px solid var(--glass-border); border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.4); z-index: 1000; overflow: hidden;">
                            <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center;">
                                <h4 style="margin: 0; font-size: 1rem;">Notifications</h4>
                                <button id="markAllRead" style="background: none; border: none; color: var(--primary-color); font-size: 0.8rem; cursor: pointer; font-weight: 600;">Mark all read</button>
                            </div>
                            <div id="notificationList" style="max-height: 400px; overflow-y: auto; padding: 0.5rem;">
                                <div style="text-align: center; padding: 2rem; color: var(--text-muted);">Loading...</div>
                            </div>
                        </div>
                    </div>
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

            <section class="student-assignment-panel" aria-labelledby="newAssignmentsHeading">
                <header class="student-assignment-header">
                    <div class="student-assignment-heading">
                        <span class="student-assignment-kicker">From your teachers</span>
                        <h2 id="newAssignmentsHeading"><i class="fas fa-book-open" aria-hidden="true"></i> New Assignments</h2>
                        <p>Download the latest materials for your group.</p>
                    </div>
                    <?php if (!empty($broadcasted)): ?>
                        <span class="premium-badge badge-blue"><?php echo count($broadcasted); ?> new</span>
                    <?php endif; ?>
                </header>

                <?php if (empty($broadcasted)): ?>
                    <div class="student-assignment-empty">
                        <i class="fas fa-inbox" aria-hidden="true"></i>
                        <h3>No new assignments</h3>
                        <p>Your teachers have not posted any materials for your group yet.</p>
                    </div>
                <?php else: ?>
                    <div class="student-assignment-list">
                        <?php foreach ($broadcasted as $assignment): ?>
                            <?php
                            $assignmentId = (int) $assignment['id'];
                            $assignmentTitle = (string) ($assignment['title'] ?? 'Untitled assignment');
                            $assignmentSubject = (string) ($assignment['subject'] ?? 'General');
                            $assignmentTeacher = (string) ($assignment['teacher_name'] ?? 'Your teacher');
                            $assignmentDescription = trim((string) ($assignment['description'] ?? ''));
                            $createdTimestamp = strtotime((string) ($assignment['created_at'] ?? ''));
                            $createdTimestamp = $createdTimestamp === false ? time() : $createdTimestamp;
                            ?>
                            <article class="student-assignment-item">
                                <div class="student-assignment-item__icon" aria-hidden="true">
                                    <i class="fas fa-file-lines"></i>
                                </div>
                                <div class="student-assignment-item__body">
                                    <div class="student-assignment-item__meta">
                                        <span class="premium-badge badge-blue"><?php echo htmlspecialchars($assignmentSubject, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <time datetime="<?php echo date('c', $createdTimestamp); ?>">
                                            <i class="fas fa-calendar-days" aria-hidden="true"></i>
                                            <?php echo date('M j, Y', $createdTimestamp); ?>
                                        </time>
                                    </div>
                                    <h3><?php echo htmlspecialchars($assignmentTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <?php if ($assignmentDescription !== ''): ?>
                                        <p class="student-assignment-item__description"><?php echo htmlspecialchars($assignmentDescription, ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                    <p class="student-assignment-item__teacher">
                                        <i class="fas fa-user-tie" aria-hidden="true"></i>
                                        Posted by <strong><?php echo htmlspecialchars($assignmentTeacher, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </p>
                                </div>
                                <a href="../controllers/download_assignment.php?id=<?php echo $assignmentId; ?>" class="premium-btn premium-btn-primary student-assignment-item__action" aria-label="Download <?php echo htmlspecialchars($assignmentTitle, ENT_QUOTES, 'UTF-8'); ?> from <?php echo htmlspecialchars($assignmentTeacher, ENT_QUOTES, 'UTF-8'); ?>" download>
                                    <i class="fas fa-download" aria-hidden="true"></i> Get copy
                                </a>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

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
                    <span style="opacity: 0.7;">Web Developer: <strong id="_sys_v_auth"><a href="https://casagan.vercel.app/" target="_blank" style="color: inherit; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwin T. Casagan</a></strong></span>
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
    <script src="../assets/js/system_loader.js?v=20260818"></script>
    <script src="../assets/js/responsive_ui.js"></script>
    <script>
        const notificationBell = document.getElementById('notificationBell');
        const notificationPanel = document.getElementById('notificationPanel');
        const notificationBadge = document.getElementById('notificationBadge');
        const notificationList = document.getElementById('notificationList');
        const markAllReadBtn = document.getElementById('markAllRead');

        function loadNotifications() {
            fetch('../controllers/ajax_get_notifications.php?action=list')
                .then(r => r.json())
                .then(data => {
                    const notifications = data.notifications || [];
                    notificationList.innerHTML = '';
                    if (notifications.length === 0) {
                        notificationList.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-muted);">No notifications yet</div>';
                        notificationBadge.style.display = 'none';
                        return;
                    }
                    let unread = 0;
                    notifications.forEach(n => {
                        if (!n.is_read) unread++;
                        const item = document.createElement('div');
                        item.className = 'notification-item ' + (n.is_read ? 'read' : 'unread');
                        item.innerHTML = '<div class="notification-title">' + escapeHtml(n.title) + '</div>' +
                            '<div class="notification-message">' + escapeHtml(n.message || '') + '</div>' +
                            '<div class="notification-time">' + formatDate(n.created_at) + '</div>';
                        item.addEventListener('click', () => {
                            fetch('../controllers/ajax_get_notifications.php?action=mark_read', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'notification_id=' + n.id
                            }).then(() => loadNotifications());
                        });
                        notificationList.appendChild(item);
                    });
                    if (unread > 0) {
                        notificationBadge.textContent = unread > 99 ? '99+' : unread;
                        notificationBadge.style.display = 'flex';
                    } else {
                        notificationBadge.style.display = 'none';
                    }
                });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(dateStr) {
            const d = new Date(dateStr);
            return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        if (notificationBell) {
            notificationBell.addEventListener('click', (e) => {
                e.stopPropagation();
                notificationPanel.style.display = notificationPanel.style.display === 'none' ? 'block' : 'none';
                if (notificationPanel.style.display === 'block') {
                    loadNotifications();
                }
            });
        }

        if (markAllReadBtn) {
            markAllReadBtn.addEventListener('click', () => {
                fetch('../controllers/ajax_get_notifications.php?action=mark_all_read', { method: 'POST' })
                    .then(() => loadNotifications());
            });
        }

        document.addEventListener('click', (e) => {
            if (notificationPanel && !notificationPanel.contains(e.target) && e.target !== notificationBell) {
                notificationPanel.style.display = 'none';
            }
        });

        loadNotifications();
        setInterval(loadNotifications, 30000);
    </script>
</body>
</html>

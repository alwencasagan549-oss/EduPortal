<?php
session_start();
require_once '../config/database.php';
requireLogin();

if (getUserRole() !== 'teacher') {
    header('Location: index.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['user_name'];
$teacher_subject = $_SESSION['user_subject'];

// Get all unique students who have submitted to this teacher's subject
$conn = getDBConnection();

$query = "SELECT st.id, st.name, st.lrn, st.grade_level, st.section, st.email,
          COUNT(s.id) as total_submissions,
          MAX(s.submitted_at) as latest_submission,
          AVG(NULLIF(CAST(s.marks AS NUMERIC), '')) as avg_marks
          FROM students st
          JOIN submissions s ON st.id = s.student_id
          WHERE s.subject = ?
          GROUP BY st.id
          ORDER BY latest_submission DESC";

$stmt = $conn->prepare($query);
$stmt->execute([$teacher_subject]);
$result = $stmt->get_result();
$students = $result->fetch_all();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $teacher_subject; ?> Students | EduPortal LMS</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <a href="dashboard.php" class="menu-link">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </li>
                <li class="menu-item">
                    <a href="post_assignment.php" class="menu-link">
                        <i class="fas fa-upload"></i> Post Assignment
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php" class="menu-link active">
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
                    <h1>Student Directory</h1>
                    <p>Managing students enrolled in <strong><?php echo htmlspecialchars($teacher_subject); ?></strong></p>
                </div>
                
                <div class="top-bar-actions">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="directorySearch" placeholder="Search by name or LRN..." onkeyup="filterDirectory()">
                    </div>
                    <button class="icon-button">
                        <i class="fas fa-bell"></i>
                    </button>
                </div>
            </header>

            <!-- Directory Stats -->
            <div class="stats-grid">
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Unique Students</span>
                        <span class="stat-value"><?php echo count($students); ?></span>
                        <div class="stat-trend up">
                            <i class="fas fa-users"></i> Enrolled
                        </div>
                    </div>
                </div>
                <div class="glass-card">
                    <div class="stat-item-premium">
                        <span class="stat-label">Average Performance</span>
                        <?php 
                        $total_avg = count($students) > 0 ? array_sum(array_column($students, 'avg_marks')) / count($students) : 0;
                        ?>
                        <span class="stat-value"><?php echo number_format($total_avg, 1); ?></span>
                        <div class="stat-trend up">
                            <i class="fas fa-chart-line"></i> Class average
                        </div>
                    </div>
                </div>
                <div class="glass-card" style="background: var(--bg-sidebar); border: 2px solid var(--primary-color);">
                    <div class="stat-item-premium">
                        <span class="stat-label" style="color: var(--primary-color);">New Enrollment</span>
                        <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-muted); line-height: 1.4;">
                            Students appear here automatically after their first submission.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Student Cards Grid -->
            <div id="studentContainer" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
                <?php if (empty($students)): ?>
                    <div class="glass-card" style="grid-column: 1 / -1; padding: 5rem; text-align: center; opacity: 0.5;">
                        <i class="fas fa-user-slash" style="font-size: 3rem; margin-bottom: 1.5rem;"></i>
                        <p>No students have submitted assignments for this subject yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                    <div class="glass-card student-card" style="padding: 1.5rem; transition: transform 0.2s;" data-name="<?php echo strtolower($student['name']); ?>" data-lrn="<?php echo $student['lrn']; ?>">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                            <div style="display: flex; gap: 1rem; align-items: center;">
                                <div style="width: 48px; height: 48px; background: rgba(78, 115, 223, 0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary-color); font-size: 1.25rem;">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                                <div>
                                    <h3 style="font-size: 1.1rem; margin-bottom: 2px;"><?php echo htmlspecialchars($student['name']); ?></h3>
                                    <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">LRN: <?php echo htmlspecialchars($student['lrn']); ?></span>
                                </div>
                            </div>
                            <span class="premium-badge badge-blue"><?php echo htmlspecialchars($student['grade_level'] ?? 'N/A'); ?></span>
                        </div>

                        <div style="background: rgba(0,0,0,0.2); border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; font-size: 0.85rem;">
                            <div>
                                <span style="color: var(--text-muted); display: block; margin-bottom: 4px;">Section</span>
                                <strong><?php echo htmlspecialchars($student['section'] ?? 'Unassigned'); ?></strong>
                            </div>
                            <div>
                                <span style="color: var(--text-muted); display: block; margin-bottom: 4px;">Submissions</span>
                                <strong><?php echo $student['total_submissions']; ?> Total</strong>
                            </div>
                        </div>

                        <div style="display: flex; gap: 8px;">
                            <a href="dashboard.php?section=<?php echo urlencode($student['section']); ?>" class="premium-btn premium-btn-primary" style="flex: 1; justify-content: center; font-size: 0.85rem; padding: 0.6rem;">
                                <i class="fas fa-eye"></i> View History
                            </a>
                            <a href="contact_student.php?id=<?php echo $student['id']; ?>" class="premium-btn premium-btn-outline" style="padding: 0.6rem; border-radius: 12px; color: var(--primary-color); border-color: rgba(78, 115, 223, 0.2);" title="Contact Student">
                                <i class="fas fa-envelope"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
    function filterDirectory() {
        const input = document.getElementById('directorySearch');
        const filter = input.value.toLowerCase();
        const cards = document.getElementsByClassName('student-card');

        for (let i = 0; i < cards.length; i++) {
            const name = cards[i].getAttribute('data-name');
            const lrn = cards[i].getAttribute('data-lrn');
            if (name.includes(filter) || lrn.includes(filter)) {
                cards[i].style.display = "";
            } else {
                cards[i].style.display = "none";
            }
        }
    }
    </script>

    <style>
        .student-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(78, 115, 223, 0.1);
        }
    </style>
</body>
</html>

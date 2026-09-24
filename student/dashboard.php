<?php
require_once '../config/database.php';
require_once '../libs/assignment_management.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: /session_expired.php');
    exit();
}

$student_id = $_SESSION['user_id'];

// Get student submissions
$conn = getDBConnection();
$historyColumns = [];
try {
    $historyColumns = assignment_submission_column_names($conn);
} catch (Throwable $exception) {
    error_log('EduPortal submission history schema check failed: ' . $exception->getMessage());
}
$historySelect = 'id, subject, file_path, marks, remarks, submitted_at';
if (isset($historyColumns['assignment_id'])) {
    $historySelect .= ', assignment_id';
}
$stmt = $conn->prepare("SELECT {$historySelect} FROM submissions WHERE student_id = ? ORDER BY submitted_at DESC LIMIT 100");
$stmt->execute([$student_id]);
$submissions = $stmt->get_result()->fetch_all();
$submittedAssignmentIds = [];
foreach ($submissions as $submission) {
    $submittedAssignmentId = assignment_id($submission['assignment_id'] ?? null);
    if ($submittedAssignmentId !== null) {
        $submittedAssignmentIds[$submittedAssignmentId] = true;
    }
}

// Get broadcasted assignments for this student's group
$student_grade = $_SESSION['user_grade'] ?? '';
$student_section = $_SESSION['user_section'] ?? '';
$student_strand = $_SESSION['user_strand'] ?? 'Academic';
$stmt2 = $conn->prepare("SELECT id, subject, title, description, file_path, teacher_name, created_at FROM posted_assignments WHERE grade_level = ? AND section = ? AND strand = ? ORDER BY created_at DESC LIMIT 100");
$stmt2->execute([$student_grade, $student_section, $student_strand]);
$broadcasted = $stmt2->get_result()->fetch_all();

require_once __DIR__ . '/nav.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | EduPortal LMS</title>
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
        body.modal-open {
            overflow: hidden;
        }
        .submission-modal[hidden] {
            display: none;
        }
        .submission-modal {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .submission-modal__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(5, 7, 13, 0.82);
            backdrop-filter: blur(12px);
        }
        .submission-modal__panel {
            position: relative;
            z-index: 1;
            width: min(100%, 620px);
            max-height: calc(100vh - 2rem);
            overflow-y: auto;
            padding: clamp(1.25rem, 4vw, 2rem);
            border: 1px solid var(--glass-border);
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.48);
        }
        .submission-modal__header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .submission-modal__eyebrow {
            margin: 0 0 0.35rem;
            color: var(--primary-color);
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .submission-modal__title {
            margin: 0;
            font-size: 1.25rem;
        }
        .submission-modal__context {
            margin: 0.4rem 0 0;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        .submission-modal__close {
            flex: 0 0 auto;
            width: 2.25rem;
            height: 2.25rem;
            border: 1px solid var(--glass-border);
            border-radius: 50%;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1.25rem;
        }
        .submission-modal__close:hover,
        .submission-modal__close:focus-visible {
            border-color: var(--primary-color);
            color: var(--text-main);
        }
        .submission-modal__actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .submission-drop-zone {
            border: 2px dashed var(--glass-border);
            border-radius: 16px;
            padding: 1.6rem;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        .submission-drop-zone:hover,
        .submission-drop-zone:focus-within {
            border-color: var(--primary-color);
            background: rgba(78, 115, 223, 0.06);
        }
    </style>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="layout-wrapper">
        <?php renderStudentNav('dashboard'); ?>

        <!-- Main Content -->
        <main class="main-content" id="main-content">
            <header class="top-bar">
                <button class="menu-toggle" type="button" aria-label="Open navigation" aria-controls="student-sidebar" aria-expanded="false">
                    <i class="fas fa-bars" aria-hidden="true"></i>
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
                        <button type="button" class="icon-button" id="notificationBell" aria-label="Notifications" aria-expanded="false" aria-controls="notificationPanel" style="position: relative;">
                            <i class="fas fa-bell"></i>
                            <span id="notificationBadge" style="display: none; position: absolute; top: -4px; right: -4px; background: var(--danger-color); color: white; font-size: 0.65rem; font-weight: 700; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">0</span>
                        </button>
                        <div id="notificationPanel" style="display: none; position: absolute; top: 48px; right: 0; width: 360px; max-height: 480px; background: var(--bg-card); border: 1px solid var(--glass-border); border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.4); z-index: 1000; overflow: hidden;">
                            <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--glass-border); display: flex; justify-content: space-between; align-items: center;">
                                <h2 style="margin: 0; font-size: 1rem;">Notifications</h2>
                                <button type="button" id="markAllRead" style="background: none; border: none; color: var(--primary-color); font-size: 0.8rem; cursor: pointer; font-weight: 600;">Mark all read</button>
                            </div>
                            <div id="notificationList" aria-live="polite" aria-busy="true" style="max-height: 400px; overflow-y: auto; padding: 0.5rem;">
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
            <?php elseif (isset($_GET['duplicate'])): ?>
                <div class="alert alert-warning" role="status" aria-live="polite">
                    <i class="fas fa-circle-info"></i>
                    <div><strong>Already submitted.</strong> The duplicate upload was removed; your original submission is unchanged.</div>
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
                            $isSubmitted = isset($submittedAssignmentIds[$assignmentId]);
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
                                <div class="student-assignment-item__actions" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                    <?php if (!$isSubmitted): ?>
                                        <a href="../controllers/download_assignment.php?id=<?php echo $assignmentId; ?>" class="premium-btn premium-btn-primary student-assignment-item__action" aria-label="Download <?php echo htmlspecialchars($assignmentTitle, ENT_QUOTES, 'UTF-8'); ?> from <?php echo htmlspecialchars($assignmentTeacher, ENT_QUOTES, 'UTF-8'); ?>" download>
                                            <i class="fas fa-download" aria-hidden="true"></i> Get copy
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($isSubmitted): ?>
                                        <span class="premium-badge badge-green" style="display: inline-flex; align-items: center; gap: 0.45rem;" role="status" aria-label="Submitted">
                                            <span aria-hidden="true" style="width: 0.45rem; height: 0.45rem; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.14);"></span>
                                            Submitted
                                        </span>
                                    <?php else: ?>
                                        <button type="button" class="premium-btn premium-btn-outline js-open-submission" data-assignment-id="<?php echo $assignmentId; ?>" data-subject="<?php echo rawurlencode($assignmentSubject); ?>" data-title="<?php echo rawurlencode($assignmentTitle); ?>" aria-label="Submit work for <?php echo htmlspecialchars($assignmentTitle, ENT_QUOTES, 'UTF-8'); ?>" aria-haspopup="dialog">
                                            <i class="fas fa-paper-plane" aria-hidden="true"></i> Submit
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="submission-modal" id="submissionModal" role="dialog" aria-modal="true" aria-labelledby="submissionModalTitle" aria-describedby="submissionModalContext" hidden>
                <div class="submission-modal__backdrop" data-close-submission></div>
                <div class="submission-modal__panel glass-card animate-scale-up">
                    <div class="submission-modal__header">
                        <div>
                            <p class="submission-modal__eyebrow">Assignment submission</p>
                            <h2 class="submission-modal__title" id="submissionModalTitle">Submit your work</h2>
                            <p class="submission-modal__context" id="submissionModalContext"></p>
                        </div>
                        <button type="button" class="submission-modal__close" data-close-submission aria-label="Close submission form">&times;</button>
                    </div>
                    <form id="submissionForm" action="../controllers/submit.php" method="POST" enctype="multipart/form-data" onsubmit="return validateForm()" data-loader="true">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="assignment_id" id="submissionAssignmentId" value="">
                        <input type="hidden" name="subject" id="submissionSubject" value="">
                        <div style="margin-bottom: 1.5rem;">
                            <label for="assignment" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.9rem;">Assignment file</label>
                            <div class="submission-drop-zone" id="dropZone" role="button" tabindex="0" aria-label="Choose an assignment file">
                                <input type="file" id="assignment" name="assignment" style="display: none;" accept=".pdf,.doc,.docx" onchange="updateFileName(this)">
                                <div id="fileInfo">
                                    <i class="fas fa-file-arrow-up" style="font-size: 2.5rem; color: var(--primary-color); margin-bottom: 1rem; opacity: 0.5;" aria-hidden="true"></i>
                                    <p style="font-size: 0.9rem; font-weight: 500;">Select document</p>
                                    <p style="font-size: 0.75rem; color: var(--text-muted);">PDF or DOCX (Max 10MB)</p>
                                </div>
                                <div id="fileName" style="display: none; font-weight: 600; color: var(--text-main);"></div>
                            </div>
                        </div>
                        <div class="submission-modal__actions">
                            <button type="button" class="premium-btn premium-btn-outline" data-close-submission>Cancel</button>
                            <button type="submit" class="premium-btn premium-btn-primary">
                                <i class="fas fa-paper-plane" aria-hidden="true"></i> Submit Assignment
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr; gap: 2rem;">
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
                                         <td>
                                             <span class="premium-badge badge-yellow"><?php echo htmlspecialchars($submission['subject']); ?></span>
                                             <?php if (!empty($submission['assignment_id'])): ?>
                                                 <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.35rem;">Assignment #<?php echo (int) $submission['assignment_id']; ?></div>
                                             <?php endif; ?>
                                         </td>

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
    const submissionModal = document.getElementById('submissionModal');
    const submissionAssignmentId = document.getElementById('submissionAssignmentId');
    const submissionSubject = document.getElementById('submissionSubject');
    const submissionModalContext = document.getElementById('submissionModalContext');
    const submissionFileInput = document.getElementById('assignment');
    const submissionDropZone = document.getElementById('dropZone');
    let lastSubmissionTrigger = null;

    submissionDropZone?.addEventListener('click', event => {
        if (event.target !== submissionFileInput) {
            submissionFileInput.click();
        }
    });

    submissionDropZone?.addEventListener('keydown', event => {
        if ((event.key === 'Enter' || event.key === ' ') && event.target !== submissionFileInput) {
            event.preventDefault();
            submissionFileInput.click();
        }
    });

    function decodeSubmissionValue(value) {
        try {
            return decodeURIComponent(value || '');
        } catch (error) {
            return value || '';
        }
    }

    function resetSubmissionFile() {
        if (!submissionFileInput) {
            return;
        }
        submissionFileInput.value = '';
        const fileInfo = document.getElementById('fileInfo');
        const fileName = document.getElementById('fileName');
        const dropZone = document.getElementById('dropZone');
        if (fileInfo) {
            fileInfo.style.display = 'block';
        }
        if (fileName) {
            fileName.style.display = 'none';
            fileName.replaceChildren();
        }
        if (dropZone) {
            dropZone.style.borderColor = '';
        }
    }

    function openSubmissionModal(trigger) {
        if (!submissionModal || !submissionAssignmentId || !submissionSubject) {
            return;
        }
        const subject = decodeSubmissionValue(trigger.dataset.subject);
        const title = decodeSubmissionValue(trigger.dataset.title) || subject || 'this assignment';
        submissionAssignmentId.value = trigger.dataset.assignmentId || '';
        submissionSubject.value = subject;
        if (submissionModalContext) {
            submissionModalContext.textContent = subject ? `${title} · ${subject}` : title;
        }
        resetSubmissionFile();
        lastSubmissionTrigger = trigger;
        submissionModal.hidden = false;
        document.body.classList.add('modal-open');
        window.setTimeout(() => submissionDropZone && submissionDropZone.focus(), 0);
    }

    function closeSubmissionModal() {
        if (!submissionModal) {
            return;
        }
        submissionModal.hidden = true;
        document.body.classList.remove('modal-open');
        if (lastSubmissionTrigger) {
            lastSubmissionTrigger.focus();
        }
    }

    document.querySelectorAll('.js-open-submission').forEach((trigger) => {
        trigger.addEventListener('click', () => openSubmissionModal(trigger));
    });

    document.querySelectorAll('[data-close-submission]').forEach((control) => {
        control.addEventListener('click', closeSubmissionModal);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && submissionModal && !submissionModal.hidden) {
            closeSubmissionModal();
        }
    });

    const queryAssignmentId = new URLSearchParams(window.location.search).get('assignment_id');
    if (queryAssignmentId) {
        document.querySelectorAll('.js-open-submission').forEach((trigger) => {
            if (trigger.dataset.assignmentId === queryAssignmentId) {
                openSubmissionModal(trigger);
            }
        });
    }

    function updateFileName(input) {
        if (input.files && input.files[0]) {
            const fileInfo = document.getElementById('fileInfo');
            const fileNameContainer = document.getElementById('fileName');
            const dropZone = document.getElementById('dropZone');
            
            fileInfo.style.display = 'none';
            fileNameContainer.style.display = 'block';
            
            // Auth Shield: Clear and safely set text content (XSS Protection)
            const fileIconMarkup = '<i class="fas fa-check-circle" style="color: var(--success-color); margin-right: 8px;"></i>';
            fileNameContainer.innerHTML = window.EduPortalTrustedTypes
                ? window.EduPortalTrustedTypes.createHTML(fileIconMarkup)
                : fileIconMarkup;
            const textNode = document.createTextNode(input.files[0].name);
            fileNameContainer.appendChild(textNode);
            
            dropZone.style.borderColor = 'var(--success-color)';
        }
    }

    function validateForm() {
        const fileInput = document.getElementById('assignment');
        const assignmentInput = document.getElementById('submissionAssignmentId');
        const subjectInput = document.getElementById('submissionSubject');

        if (!assignmentInput || !assignmentInput.value) {
            alert('Choose a posted assignment first');
            return false;
        }
        if (!subjectInput || !subjectInput.value.trim()) {
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
    <script src="../assets/js/system_loader.js?v=20260924-loader3"></script>
    <script src="../assets/js/responsive_ui.js"></script>
    <script src="../assets/js/pwa.js"></script>
    <script>
        const notificationBell = document.getElementById('notificationBell');
        const notificationPanel = document.getElementById('notificationPanel');
        const notificationBadge = document.getElementById('notificationBadge');
        const notificationList = document.getElementById('notificationList');
        const markAllReadBtn = document.getElementById('markAllRead');
        const notificationEndpoint = '../controllers/ajax_get_notifications.php';
        let notificationRequest = null;
        let notificationMutationPending = false;
        let notificationPollTimer = null;
        const activeNotificationControllers = new Set();

        const setNotificationMessage = (message, isError = false) => {
            const wrapper = document.createElement('div');
            wrapper.className = isError ? 'alert alert-danger' : 'notification-empty';
            wrapper.setAttribute('role', isError ? 'alert' : 'status');
            wrapper.textContent = message;
            notificationList.replaceChildren(wrapper);
        };

        const escapeHtml = text => {
            const entities = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            return String(text ?? '').replace(/[&<>"']/g, character => entities[character]);
        };
        const trustedHtml = markup => window.EduPortalTrustedTypes
            ? window.EduPortalTrustedTypes.createHTML(markup)
            : markup;

        const formatDate = dateStr => {
            const date = new Date(dateStr);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        };

        const requestNotifications = async (url, options = {}) => {
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 15000);
            activeNotificationControllers.add(controller);
            try {
                const response = await fetch(url, { ...options, cache: 'no-store', signal: controller.signal });
                const contentType = response.headers.get('content-type') || '';
                if (response.redirected || !contentType.includes('application/json')) {
                    const error = new Error('Your session has expired. Sign in again to continue.');
                    error.sessionExpired = true;
                    throw error;
                }
                const data = await response.json().catch(() => {
                    const error = new Error('The server returned an invalid notification response.');
                    error.sessionExpired = false;
                    throw error;
                });
                if (!response.ok || data.error) {
                    const error = new Error(data.error || `Request failed (${response.status})`);
                    error.sessionExpired = response.status === 401;
                    throw error;
                }
                return data;
            } finally {
                activeNotificationControllers.delete(controller);
                window.clearTimeout(timeout);
            }
        };

        const renderNotifications = notifications => {
            notificationList.replaceChildren();
            if (notifications.length === 0) {
                setNotificationMessage('No notifications yet');
                notificationBadge.style.display = 'none';
                return;
            }

            let unread = 0;
            notifications.forEach(notification => {
                if (!notification.is_read) {
                    unread += 1;
                }
                const item = document.createElement('div');
                item.className = 'notification-item ' + (notification.is_read ? 'read' : 'unread');
                item.setAttribute('role', 'button');
                item.setAttribute('tabindex', '0');
                item.setAttribute('aria-label', `Mark notification as read: ${notification.title}`);
                item.innerHTML = trustedHtml('<div class="notification-title">' + escapeHtml(notification.title) + '</div>' +
                    '<div class="notification-message">' + escapeHtml(notification.message) + '</div>' +
                    '<div class="notification-time">' + escapeHtml(formatDate(notification.created_at)) + '</div>');
                item.addEventListener('keydown', event => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        item.click();
                    }
                });
                item.addEventListener('click', async () => {
                    if (item.dataset.pending === 'true' || notificationMutationPending) {
                        return;
                    }
                    notificationMutationPending = true;
                    item.dataset.pending = 'true';
                    item.setAttribute('aria-busy', 'true');
                    try {
                        await requestNotifications(notificationEndpoint + '?action=mark_read', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'notification_id=' + encodeURIComponent(notification.id)
                        });
                        await loadNotifications({ showLoading: false });
                    } catch (error) {
                        setNotificationMessage(error.name === 'AbortError'
                            ? 'Updating the notification timed out.'
                            : error.sessionExpired
                                ? error.message
                                : 'The notification could not be updated.', true);
                    } finally {
                        notificationMutationPending = false;
                        if (item.isConnected) {
                            item.removeAttribute('data-pending');
                            item.removeAttribute('aria-busy');
                        }
                    }
                });
                notificationList.appendChild(item);
            });

            notificationBadge.textContent = unread > 99 ? '99+' : unread;
            notificationBadge.style.display = unread > 0 ? 'flex' : 'none';
        };

        async function loadNotifications({ showLoading = true } = {}) {
            if (notificationRequest) {
                return notificationRequest;
            }
            if (showLoading) {
                setNotificationMessage('Loading notifications...');
            }
            notificationList.setAttribute('aria-busy', 'true');
            notificationRequest = requestNotifications(notificationEndpoint + '?action=list')
                .then(data => renderNotifications(data.notifications || []))
                .catch(error => {
                    if (error.sessionExpired) {
                        setNotificationMessage(error.message, true);
                        return;
                    }
                    if (!showLoading && notificationList.childElementCount > 0) {
                        return;
                    }
                    setNotificationMessage(error.name === 'AbortError'
                        ? 'Loading notifications timed out.'
                        : 'Notifications could not be loaded.', true);
                })
                .finally(() => {
                    notificationList.removeAttribute('aria-busy');
                    notificationRequest = null;
                });
            return notificationRequest;
        }

        const scheduleNotificationPoll = (delay = 30000) => {
            window.clearTimeout(notificationPollTimer);
            if (!document.hidden) {
                notificationPollTimer = window.setTimeout(async () => {
                    if (!notificationMutationPending) {
                        await loadNotifications({ showLoading: false });
                    }
                    scheduleNotificationPoll();
                }, delay);
            }
        };

        notificationBell?.addEventListener('click', event => {
            event.stopPropagation();
            const willOpen = notificationPanel.style.display === 'none';
            notificationPanel.style.display = willOpen ? 'block' : 'none';
            notificationBell.setAttribute('aria-expanded', String(willOpen));
            if (willOpen) {
                loadNotifications({ showLoading: true });
            }
        });

        markAllReadBtn?.addEventListener('click', async () => {
            if (markAllReadBtn.disabled || notificationMutationPending) {
                return;
            }
            notificationMutationPending = true;
            markAllReadBtn.disabled = true;
            markAllReadBtn.setAttribute('aria-busy', 'true');
            try {
                if (notificationRequest) {
                    await notificationRequest;
                }
                await requestNotifications(notificationEndpoint + '?action=mark_all_read', { method: 'POST' });
                await loadNotifications({ showLoading: false });
            } catch (error) {
                setNotificationMessage(error.name === 'AbortError'
                    ? 'Marking all notifications read timed out.'
                    : error.sessionExpired
                        ? error.message
                        : 'Notifications could not be updated.', true);
            } finally {
                notificationMutationPending = false;
                markAllReadBtn.disabled = false;
                markAllReadBtn.removeAttribute('aria-busy');
            }
        });

        document.addEventListener('click', event => {
            if (notificationPanel && !notificationPanel.contains(event.target) && event.target !== notificationBell) {
                notificationPanel.style.display = 'none';
                notificationBell.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                window.clearTimeout(notificationPollTimer);
            } else {
                scheduleNotificationPoll(0);
            }
        });
        window.addEventListener('pagehide', () => {
            window.clearTimeout(notificationPollTimer);
            activeNotificationControllers.forEach(controller => controller.abort());
        });

        loadNotifications({ showLoading: true });
        scheduleNotificationPoll();
    </script>
</body>
</html>

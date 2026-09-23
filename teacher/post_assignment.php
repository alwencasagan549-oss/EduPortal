<?php
/**
 * Teacher Portal: Post Selective Assignment
 * Enhanced with modern chunked upload system.
 */

require_once '../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
requireLogin();

if (getUserRole() !== 'teacher') {
    header('Location: /session_expired.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['user_name'];
$teacher_subject = $_SESSION['user_subject'];

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT DISTINCT grade_level FROM students ORDER BY grade_level");
$stmt->execute();
$grades = $stmt->get_result()->fetch_all();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Assignment | EduPortal Teacher</title>
    <link rel="icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
</head>

<body>
    <div class="layout-wrapper">
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
                    <a href="post_assignment.php" class="menu-link active">
                        <i class="fas fa-upload"></i> Post Assignment
                    </a>
                </li>
                <li class="menu-item">
                    <a href="students.php" class="menu-link">
                        <i class="fas fa-user-graduate"></i> My Students
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
                <form method="POST" action="../logout.php" style="display:inline;" onsubmit="return confirm('Are you sure you want to logout?')">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <button type="submit" class="logout-link">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </button>
                </form>
                <div style="padding: 8px 0 0; text-align: center; opacity: 0.4; font-size: 0.65rem; color: var(--text-muted);">
                    <span id="_sys_v_auth" style="display: none;">Alwin T. Casagan</span>
                </div>
            </div>
        </aside>

        <main class="main-content">
            <header class="top-bar">
                <button class="menu-toggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="page-title">
                    <h1>Broadcast Assignment</h1>
                    <p>Deliver study materials to selective student groups.</p>
                </div>
            </header>

            <div id="statusAlert"></div>

            <div class="glass-card animate-fade-up" style="max-width: 800px; margin: 0 auto; padding: 3rem;">
                <form id="postAssignmentForm" data-loader="true">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                        <div>
                            <label class="premium-label" style="display: block; margin-bottom: 1rem;">Target Grade Level</label>
                            <select name="grade_level" required class="premium-input" id="gradeLevelSelect">
                                <option value="">Select Grade</option>
                                <?php foreach ($grades as $g): ?>
                                    <option value="<?php echo htmlspecialchars($g['grade_level']); ?>"><?php echo htmlspecialchars($g['grade_level']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="premium-label" style="display: block; margin-bottom: 1rem;">Target Strand</label>
                            <select name="strand" id="strandSelect" required class="premium-input">
                                <option value="">Select Strand</option>
                                <option value="Academic">Academic</option>
                                <option value="Tech-pro">Tech-pro</option>
                            </select>
                        </div>
                    </div>
                    <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                        <div>
                            <label class="premium-label" style="display: block; margin-bottom: 1rem;">Target Section</label>
                            <select name="section" id="sectionSelect" required class="premium-input" disabled>
                                <option value="">Select Grade & Strand First</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom: 2rem;">
                        <label class="premium-label" style="display: block; margin-bottom: 1rem;">Assignment Title</label>
                        <input type="text" name="title" required class="premium-input" placeholder="e.g. Chapter 4: Data Structures Review">
                    </div>

                    <div style="margin-bottom: 2.5rem;">
                        <label class="premium-label" style="display: block; margin-bottom: 1rem;">Detailed Instructions</label>
                        <textarea name="description" rows="6" class="premium-input" style="resize: none;" placeholder="Provide context and deadlines..."></textarea>
                    </div>

                    <div style="margin-bottom: 3rem;">
                        <label class="premium-label">Assignment Soft Copy (PDF, Word, etc.)</label>
                        <div class="upload-zone" id="dropZone" onclick="document.getElementById('fileInput').click()" style="margin-top: 1rem; position: relative;">
                            <input type="file" id="fileInput" name="assignment_file" style="display: none;" accept=".pdf,.doc,.docx,.zip,.jpg,.jpeg,.png,.mp4,.txt">
                            <div id="uploadEmptyState" class="upload-empty-state">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: var(--primary-color); margin-bottom: 1rem; opacity: 0.6;"></i>
                                <p style="font-weight: 500; margin-bottom: 0.5rem;">Click or drag to upload assignment</p>
                                <p style="font-size: 0.8rem; color: var(--text-muted);">PDF, DOC, DOCX, ZIP, Images, MP4 — max 500MB</p>
                            </div>
                            <div id="uploadQueue" class="upload-queue"></div>
                        </div>
                    </div>

                    <button type="submit" class="premium-btn premium-btn-primary" style="width: 100%; justify-content: center; padding: 1.2rem;">
                        <i class="fas fa-paper-plane"></i> Publish Assignment
                    </button>
                </form>
            </div>
        </main>
    </div>

    <script src="../assets/js/system_loader.js"></script>
    <script src="../assets/js/responsive_ui.js"></script>
    <script type="module">
        import { UploadManager } from '../assets/js/uploads/index.js';

        const uploadManager = new UploadManager(document.body, {
            dropZoneSelector: '#dropZone',
            queueSelector: '#uploadQueue',
            emptyStateSelector: '#uploadEmptyState',
            maxSize: 500 * 1024 * 1024
        });

        window.uploadManager = uploadManager;

        document.getElementById('postAssignmentForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const queue = uploadManager.getQueue();
            const completed = queue.filter(u => u.state === 'completed');

            if (completed.length === 0) {
                const statusAlert = document.getElementById('statusAlert');
                statusAlert.innerHTML = `
                    <div class="alert alert-danger animate-fade-up">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>Please upload and complete at least one file before publishing.</div>
                    </div>
                `;
                return;
            }

            const formData = new FormData(this);
            const upload = completed[0];

            formData.set('assignment_file', upload.file);

            EduPortal.showLoader("Publishing Content", "Uploading assignment and notifying students...");

            try {
                const response = await fetch('../controllers/ajax_post_assignment.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    EduPortal.showSuccessModal("Assignment Published", `${data.total_notified} students have been notified.`);
                    this.reset();
                    document.getElementById('uploadQueue').innerHTML = '';
                    document.getElementById('uploadEmptyState').style.display = 'block';
                } else {
                    EduPortal.hideLoader();
                    const statusAlert = document.getElementById('statusAlert');
                    statusAlert.innerHTML = `
                        <div class="alert alert-danger animate-fade-up">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>${data.error || "Failed to publish assignment."}</div>
                        </div>
                    `;
                }
            } catch (err) {
                EduPortal.hideLoader();
                const statusAlert = document.getElementById('statusAlert');
                statusAlert.innerHTML = `
                    <div class="alert alert-danger animate-fade-up">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div><strong>Server Error</strong> — check console for details.</div>
                    </div>
                `;
                console.error('Assignment publish failed:', err);
            }
        });
    </script>

    <script>
        document.getElementById('gradeLevelSelect').addEventListener('change', function() {
            const grade = this.value;
            const strand = document.getElementById('strandSelect').value;
            const sectionSelect = document.getElementById('sectionSelect');

            sectionSelect.innerHTML = '<option value="">Loading Sections...</option>';
            sectionSelect.disabled = true;

            if (!grade || !strand) {
                sectionSelect.innerHTML = '<option value="">Select Grade & Strand First</option>';
                return;
            }

            fetch(`../controllers/ajax_get_sections_by_grade.php?grade_level=${encodeURIComponent(grade)}&strand=${encodeURIComponent(strand)}`)
                .then(res => res.json())
                .then(sections => {
                    sectionSelect.innerHTML = '<option value="">Select Section</option>';
                    if (sections.length > 0) {
                        sections.forEach(s => {
                            const opt = document.createElement('option');
                            opt.value = s;
                            opt.textContent = s;
                            sectionSelect.appendChild(opt);
                        });
                        sectionSelect.disabled = false;
                    } else {
                        sectionSelect.innerHTML = '<option value="">No Sections Available</option>';
                    }
                })
                .catch(err => {
                    console.error("Section Fetch Error:", err);
                    sectionSelect.innerHTML = '<option value="">Error loading sections</option>';
                });
        });

        document.getElementById('strandSelect').addEventListener('change', function() {
            const grade = document.getElementById('gradeLevelSelect').value;
            const strand = this.value;
            const sectionSelect = document.getElementById('sectionSelect');

            sectionSelect.innerHTML = '<option value="">Loading Sections...</option>';
            sectionSelect.disabled = true;

            if (!grade || !strand) {
                sectionSelect.innerHTML = '<option value="">Select Grade & Strand First</option>';
                return;
            }

            fetch(`../controllers/ajax_get_sections_by_grade.php?grade_level=${encodeURIComponent(grade)}&strand=${encodeURIComponent(strand)}`)
                .then(res => res.json())
                .then(sections => {
                    sectionSelect.innerHTML = '<option value="">Select Section</option>';
                    if (sections.length > 0) {
                        sections.forEach(s => {
                            const opt = document.createElement('option');
                            opt.value = s;
                            opt.textContent = s;
                            sectionSelect.appendChild(opt);
                        });
                        sectionSelect.disabled = false;
                    } else {
                        sectionSelect.innerHTML = '<option value="">No Sections Available</option>';
                    }
                })
                .catch(err => {
                    console.error("Section Fetch Error:", err);
                    sectionSelect.innerHTML = '<option value="">Error loading sections</option>';
                });
        });
    </script>
</body>

</html>

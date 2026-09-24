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

require_once __DIR__ . '/nav.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Assignment | EduPortal Teacher</title>
    <link rel="icon" href="../assets/favicon.ico?v=20260924-ico" type="image/x-icon">
    <link rel="manifest" href="../manifest.webmanifest">
    <meta name="theme-color" content="#0a0b10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="../assets/pwa-icon-192.svg">
    <link rel="stylesheet" href="../assets/style.min.css?v=20260924">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <meta name="csrf-token" content="<?php echo csrf_token(); ?>">
</head>

<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="layout-wrapper">
        <?php renderTeacherNav('post_assignment', $teacher_subject, $teacher_name); ?>

        <main class="main-content" id="main-content">
            <header class="top-bar">
                <button class="menu-toggle" type="button" aria-label="Open navigation" aria-controls="teacher-sidebar" aria-expanded="false">
                    <i class="fas fa-bars" aria-hidden="true"></i>
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
                        <div class="upload-zone" id="dropZone" role="button" tabindex="0" style="margin-top: 1rem; position: relative;">
                            <input type="file" id="fileInput" name="assignment_file" style="display: none;" accept=".pdf,.doc,.docx,.zip,.jpg,.jpeg,.png,.mp4,.txt">
                            <div id="uploadEmptyState" class="upload-empty-state">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 3rem; color: var(--primary-color); margin-bottom: 1rem; opacity: 0.6;"></i>
                                <p style="font-weight: 500; margin-bottom: 0.5rem;">Click or drag to upload assignment</p>
                                <p style="font-size: 0.8rem; color: var(--text-muted);">PDF, DOC, DOCX, ZIP, Images, MP4 — max 9MB</p>
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

    <script src="../assets/js/system_loader.js?v=20260924-loader4"></script>
    <script src="../assets/js/responsive_ui.js"></script>
    <script src="../assets/js/pwa.js"></script>
    <script type="module">
        import { UploadManager } from '../assets/js/uploads/index.js?v=20260924-uploads3';

        const uploadManager = new UploadManager(document.body, {
            dropZoneSelector: '#dropZone',
            queueSelector: '#uploadQueue',
            emptyStateSelector: '#uploadEmptyState',
            maxSize: 9 * 1024 * 1024
        });

        window.uploadManager = uploadManager;

        const postAssignmentForm = document.getElementById('postAssignmentForm');
        const publishButton = postAssignmentForm.querySelector('button[type="submit"]');
        const assignmentSection = document.getElementById('sectionSelect');
        const publishStatus = document.getElementById('statusAlert');
        let publishController = null;

        const showPublishStatus = (message, type = 'danger') => {
            publishStatus.replaceChildren();
            if (!message) {
                return;
            }
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} animate-fade-up`;
            alert.setAttribute('role', 'alert');
            alert.textContent = message;
            publishStatus.appendChild(alert);
        };

        document.body.addEventListener('upload:onValidationError', event => {
            showPublishStatus(event.detail.error || 'The selected file is not valid.');
        });
        document.body.addEventListener('upload:onUploadError', event => {
            showPublishStatus(event.detail.error || 'The file upload failed.');
        });

        postAssignmentForm.addEventListener('submit', async function(event) {
            event.preventDefault();
            if (this.dataset.submitting === 'true') {
                return;
            }
            if (!assignmentSection || assignmentSection.disabled || !assignmentSection.value) {
                event.preventDefault();
                EduPortal.hideLoader();
                showPublishStatus('Select an available section before publishing.');
                return;
            }

            const completed = uploadManager.getQueue().filter(upload => upload.state === 'completed');
            if (completed.length === 0) {
                EduPortal.hideLoader();
                showPublishStatus('Please upload and complete at least one file before publishing.');
                return;
            }

            this.dataset.submitting = 'true';
            publishButton.disabled = true;
            publishButton.setAttribute('aria-busy', 'true');
            showPublishStatus('');

            const formData = new FormData(this);
            formData.set('assignment_file', completed[0].file);
            const controller = new AbortController();
            publishController = controller;
            const timeout = window.setTimeout(() => controller.abort(), 90000);
            EduPortal.showLoader('Publishing assignment', 'Notifying students and preparing the assignment...', { timeout: 120000 });

            try {
                const response = await fetch('../controllers/ajax_post_assignment.php', {
                    method: 'POST',
                    body: formData,
                    signal: controller.signal
                });
                const contentType = response.headers.get('content-type') || '';
                if (response.redirected || !contentType.includes('application/json')) {
                    const error = new Error('Your session has expired. Sign in again to continue.');
                    error.sessionExpired = true;
                    throw error;
                }
                const data = await response.json().catch(() => {
                    throw new Error('The server returned an invalid response.');
                });
                if (!response.ok || !data.success) {
                    throw new Error(data.error || 'Failed to publish assignment.');
                }
                uploadManager.clear();
                this.reset();
                EduPortal.showSuccessModal('Assignment Published', `${data.total_notified} students have been notified.`);
            } catch (error) {
                EduPortal.hideLoader();
                showPublishStatus(error.name === 'AbortError'
                    ? 'Publishing timed out. Check your connection and try again.'
                    : error.sessionExpired
                        ? error.message
                        : (error.message || 'The assignment could not be published.'));
            } finally {
                window.clearTimeout(timeout);
                if (publishController === controller) {
                    publishController = null;
                }
                this.dataset.submitting = 'false';
                publishButton.disabled = !assignmentSection || assignmentSection.disabled || !assignmentSection.value;
                publishButton.removeAttribute('aria-busy');
            }
        });

        window.addEventListener('pagehide', () => {
            publishController?.abort();
            uploadManager.clear();
        });
    </script>

    <script>
        const gradeLevelSelect = document.getElementById('gradeLevelSelect');
        const strandSelect = document.getElementById('strandSelect');
        const sectionSelect = document.getElementById('sectionSelect');
        let sectionRequestController = null;
        let sectionRequestId = 0;

        const updatePublishButton = () => {
            publishButton.disabled = postAssignmentForm.dataset.submitting === 'true' ||
                !sectionSelect.value ||
                sectionSelect.disabled;
        };

        publishButton.disabled = true;

        async function loadSections() {
            const requestId = ++sectionRequestId;
            sectionRequestController?.abort();
            sectionSelect.replaceChildren();
            sectionSelect.disabled = true;
            sectionSelect.setAttribute('aria-busy', 'true');
            publishButton.disabled = true;
            publishButton.setAttribute('aria-busy', 'true');

            const grade = gradeLevelSelect.value;
            const strand = strandSelect.value;
            if (!grade || !strand) {
                sectionSelect.innerHTML = '<option value="">Select Grade & Strand First</option>';
                sectionSelect.removeAttribute('aria-busy');
                publishButton.removeAttribute('aria-busy');
                updatePublishButton();
                return;
            }

            const loadingOption = document.createElement('option');
            loadingOption.value = '';
            loadingOption.textContent = 'Loading sections...';
            sectionSelect.appendChild(loadingOption);

            const controller = new AbortController();
            sectionRequestController = controller;
            const timeout = window.setTimeout(() => controller.abort(), 15000);

            try {
                const query = new URLSearchParams({ grade_level: grade, strand });
                const response = await fetch('../controllers/ajax_get_sections_by_grade.php?' + query.toString(), {
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal
                });
                const contentType = response.headers.get('content-type') || '';
                if (response.redirected || !contentType.includes('application/json')) {
                    const error = new Error('Your session has expired. Sign in again to continue.');
                    error.sessionExpired = true;
                    throw error;
                }
                const sections = await response.json().catch(() => {
                    throw new Error('Sections could not be loaded.');
                });
                if (!response.ok || !Array.isArray(sections)) {
                    throw new Error('Sections could not be loaded.');
                }
                if (requestId !== sectionRequestId) {
                    return;
                }

                sectionSelect.replaceChildren();
                if (sections.length === 0) {
                    const emptyOption = document.createElement('option');
                    emptyOption.value = '';
                    emptyOption.textContent = 'No Sections Available';
                    sectionSelect.appendChild(emptyOption);
                    return;
                }

                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.textContent = 'Select Section';
                sectionSelect.appendChild(defaultOption);
                sections.forEach(value => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = value;
                    sectionSelect.appendChild(option);
                });
                sectionSelect.disabled = false;
                updatePublishButton();
            } catch (error) {
                if (requestId !== sectionRequestId) {
                    return;
                }
                if (error.name === 'AbortError') {
                    sectionSelect.innerHTML = '<option value="">Sections timed out. Try again.</option>';
                } else {
                    sectionSelect.innerHTML = `<option value="">${error.sessionExpired ? 'Session expired. Sign in again.' : 'Unable to load sections'}</option>`;
                }
            } finally {
                window.clearTimeout(timeout);
                if (requestId === sectionRequestId) {
                    sectionSelect.removeAttribute('aria-busy');
                    publishButton.removeAttribute('aria-busy');
                    sectionRequestController = null;
                }
            }
        }

        gradeLevelSelect.addEventListener('change', loadSections);
        strandSelect.addEventListener('change', loadSections);
        sectionSelect.addEventListener('change', updatePublishButton);
        window.addEventListener('pagehide', () => {
            sectionRequestId += 1;
            sectionRequestController?.abort();
        });
    </script>
</body>

</html>

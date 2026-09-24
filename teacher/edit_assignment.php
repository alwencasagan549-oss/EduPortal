<?php

require_once '../config/database.php';
require_once '../libs/assignment_management.php';

requireLogin();

if (getUserRole() !== 'teacher') {
    header('Location: /session_expired.php');
    exit();
}

$assignmentId = assignment_id($_GET['id'] ?? null);
if ($assignmentId === null) {
    assignment_flash('error', 'That assignment request was invalid.');
    header('Location: assignments.php');
    exit();
}

$teacherId = (int) $_SESSION['user_id'];
$teacherName = $_SESSION['user_name'] ?? 'Teacher';
$teacherSubject = $_SESSION['user_subject'] ?? '';
$conn = getDBConnection();
$stmt = $conn->prepare('SELECT id, title, description, file_path, grade_level, strand, section FROM posted_assignments WHERE id = ? AND teacher_id = ?');
$stmt->execute([$assignmentId, $teacherId]);
$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment) {
    assignment_flash('error', 'That assignment was not found or is no longer available.');
    header('Location: assignments.php');
    exit();
}

$formValues = [
    'grade_level' => (string) ($assignment['grade_level'] ?? ''),
    'strand' => (string) ($assignment['strand'] ?? ''),
    'section' => (string) ($assignment['section'] ?? ''),
    'title' => (string) ($assignment['title'] ?? ''),
    'description' => (string) ($assignment['description'] ?? '')
];
$draft = assignment_take_draft($assignmentId);
if ($draft !== null) {
    $formValues = array_merge($formValues, $draft);
}

$selectedGrade = $formValues['grade_level'];
$selectedStrand = $formValues['strand'];
$gradeStmt = $conn->prepare('SELECT DISTINCT grade_level FROM students ORDER BY grade_level');
$gradeStmt->execute();
$grades = $gradeStmt->get_result()->fetch_all();
$sectionStmt = $conn->prepare('SELECT DISTINCT section FROM students WHERE grade_level = ? AND strand = ? ORDER BY section');
$sectionStmt->execute([$selectedGrade, $selectedStrand]);
$sections = $sectionStmt->get_result()->fetch_all();
$flash = assignment_take_flash();
$currentFile = basename(str_replace('\\', '/', (string) ($assignment['file_path'] ?? '')));

require_once __DIR__ . '/nav.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Posted Assignment | EduPortal LMS</title>
    <link rel="icon" href="../assets/favicon.ico" type="image/x-icon">
    <link rel="manifest" href="../manifest.webmanifest">
    <meta name="theme-color" content="#0a0b10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="../assets/pwa-icon-192.svg">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <div class="layout-wrapper">
        <?php renderTeacherNav('assignments', $teacherSubject, $teacherName); ?>

        <main class="main-content" id="main-content">
            <header class="top-bar">
                <button class="menu-toggle" type="button" aria-label="Open navigation" aria-controls="teacher-sidebar" aria-expanded="false">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>
                <div class="page-title">
                    <h1>Edit Posted Assignment</h1>
                    <p>Keep the instructions and target group current for your students.</p>
                </div>
                <div class="top-bar-actions">
                    <a href="assignments.php" class="premium-btn premium-btn-outline">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i> Back
                    </a>
                </div>
            </header>

            <?php if ($flash): ?>
                <div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-danger'; ?>" role="alert" aria-live="assertive">
                    <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>" aria-hidden="true"></i>
                    <div><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>

            <div class="assignment-edit-layout">
                <section class="glass-card assignment-form-card" aria-labelledby="edit-heading">
                    <div class="assignment-form-heading">
                        <div class="assignment-form-heading__icon" aria-hidden="true"><i class="fas fa-pen-to-square" aria-hidden="true"></i></div>
                        <div>
                            <span class="assignment-eyebrow">Assignment #<?php echo $assignmentId; ?></span>
                            <h2 id="edit-heading">Update assignment details</h2>
                        </div>
                    </div>

                    <form method="POST" action="../controllers/manage_assignment.php" enctype="multipart/form-data" data-loader="true">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="assignment_id" value="<?php echo $assignmentId; ?>">

                        <div class="assignment-form-grid">
                            <div class="assignment-field">
                                <label for="gradeLevel">Target grade level</label>
                                <select name="grade_level" id="gradeLevel" class="premium-input" required>
                                    <option value="">Select grade</option>
                                    <?php
                                    $gradeValues = [];
                                    foreach ($grades as $grade) {
                                        $gradeValues[] = (string) $grade['grade_level'];
                                    }
                                    if ($selectedGrade !== '' && !in_array($selectedGrade, $gradeValues, true)) {
                                        $gradeValues[] = $selectedGrade;
                                    }
                                    foreach ($gradeValues as $grade):
                                        ?>
                                        <option value="<?php echo htmlspecialchars($grade, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedGrade === $grade ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($grade, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="assignment-field">
                                <label for="strand">Target strand</label>
                                <select name="strand" id="strand" class="premium-input" required>
                                    <option value="">Select strand</option>
                                    <?php foreach (['Academic', 'Tech-pro'] as $strand): ?>
                                        <option value="<?php echo $strand; ?>" <?php echo $selectedStrand === $strand ? 'selected' : ''; ?>><?php echo $strand; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="assignment-field">
                                <label for="section">Target section</label>
                                <select name="section" id="section" class="premium-input" required aria-describedby="sectionStatus">
                                    <option value="">Select grade and strand first</option>
                                    <?php
                                    $sectionValues = [];
                                    foreach ($sections as $sectionRow) {
                                        $sectionValues[] = (string) $sectionRow['section'];
                                    }
                                    if ($formValues['section'] !== '' && !in_array($formValues['section'], $sectionValues, true)) {
                                        $sectionValues[] = $formValues['section'];
                                    }
                                    foreach ($sectionValues as $section):
                                        ?>
                                        <option value="<?php echo htmlspecialchars($section, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formValues['section'] === $section ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($section, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p id="sectionStatus" class="assignment-field__help" role="status" aria-live="polite"></p>
                            </div>
                        </div>

                        <div class="assignment-field">
                            <label for="title">Assignment title</label>
                            <input type="text" name="title" id="title" class="premium-input" maxlength="255" required value="<?php echo htmlspecialchars($formValues['title'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. Chapter 4: Data Structures Review">
                        </div>

                        <div class="assignment-field">
                            <label for="description">Detailed instructions</label>
                            <textarea name="description" id="description" class="premium-input" rows="7" maxlength="20000" placeholder="Add context, expectations, or a deadline..."><?php echo htmlspecialchars($formValues['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="assignment-field">
                            <label for="assignment_file">Replace assignment file <span class="assignment-field__optional">Optional</span></label>
                            <div class="assignment-current-file">
                                <i class="fas fa-paperclip" aria-hidden="true"></i>
                                <span><?php echo htmlspecialchars($currentFile, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <input type="file" name="assignment_file" id="assignment_file" class="premium-input assignment-file-input" accept=".pdf,.doc,.docx,.txt,.zip,.jpg,.jpeg,.png,.mp4" aria-describedby="fileHelp">
                            <p id="fileHelp" class="assignment-field__help">Leave this empty to keep the current file. Replacement files must be 9 MB or smaller.</p>
                        </div>

                        <div class="assignment-form-actions">
                            <button type="submit" class="premium-btn premium-btn-primary">
                                <i class="fas fa-save" aria-hidden="true"></i> Save Changes
                            </button>
                            <a href="assignments.php" class="premium-btn premium-btn-outline">Cancel</a>
                        </div>
                    </form>
                </section>

                <aside class="assignment-edit-aside" aria-label="Assignment details">
                    <div class="glass-card assignment-preview-card">
                        <span class="assignment-eyebrow">Live preview</span>
                        <h2 id="previewTitle"><?php echo htmlspecialchars($formValues['title'] !== '' ? $formValues['title'] : 'Untitled assignment', ENT_QUOTES, 'UTF-8'); ?></h2>
                        <div class="assignment-target">
                            <span class="premium-badge badge-blue" id="previewGrade"><?php echo htmlspecialchars($selectedGrade !== '' ? $selectedGrade : 'Grade', ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="premium-badge badge-yellow" id="previewStrand"><?php echo htmlspecialchars($selectedStrand !== '' ? $selectedStrand : 'Strand', ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="assignment-target__section" id="previewSection"><?php echo htmlspecialchars($formValues['section'] !== '' ? 'Section ' . $formValues['section'] : 'Section', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <p id="previewDescription"><?php echo htmlspecialchars($formValues['description'] !== '' ? $formValues['description'] : 'Your instructions will appear here.', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <div class="glass-card assignment-tip-card">
                        <i class="fas fa-lightbulb" aria-hidden="true"></i>
                        <div>
                            <strong>Keep it current</strong>
                            <p>Students see the updated title, instructions, and target group immediately after you save.</p>
                        </div>
                    </div>
                </aside>
            </div>
        </main>
    </div>

    <script src="../assets/js/system_loader.js?v=20260924"></script>
    <script src="../assets/js/responsive_ui.js?v=20260924"></script>
    <script src="../assets/js/pwa.js"></script>
    <script>
        const gradeLevel = document.getElementById('gradeLevel');
        const strand = document.getElementById('strand');
        const section = document.getElementById('section');
        const sectionStatus = document.getElementById('sectionStatus');
        const title = document.getElementById('title');
        const description = document.getElementById('description');
        const previewTitle = document.getElementById('previewTitle');
        const previewGrade = document.getElementById('previewGrade');
        const previewStrand = document.getElementById('previewStrand');
        const previewSection = document.getElementById('previewSection');
        const previewDescription = document.getElementById('previewDescription');
        const editForm = document.querySelector('.assignment-form-card form');
        let hasUnsavedChanges = false;

        if (editForm) {
            editForm.addEventListener('input', () => {
                hasUnsavedChanges = true;
            });
            editForm.addEventListener('change', () => {
                hasUnsavedChanges = true;
            });
            editForm.addEventListener('submit', () => {
                hasUnsavedChanges = false;
            });
            window.addEventListener('beforeunload', event => {
                if (hasUnsavedChanges) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
        }

        let sectionRequestId = 0;

        function updatePreview() {
            previewTitle.textContent = title.value.trim() || 'Untitled assignment';
            previewGrade.textContent = gradeLevel.value || 'Grade';
            previewStrand.textContent = strand.value || 'Strand';
            previewSection.textContent = section.value ? 'Section ' + section.value : 'Section';
            previewDescription.textContent = description.value.trim() || 'Your instructions will appear here.';
        }

        function loadSections() {
            const requestId = ++sectionRequestId;
            const previousSection = section.value;
            section.innerHTML = '<option value="">Loading sections...</option>';
            section.disabled = true;
            section.removeAttribute('aria-invalid');
            sectionStatus.textContent = 'Loading available sections.';

            if (!gradeLevel.value || !strand.value) {
                section.innerHTML = '<option value="">Select grade and strand first</option>';
                sectionStatus.textContent = 'Choose a grade and strand to load sections.';
                updatePreview();
                return;
            }

            const query = new URLSearchParams({
                grade_level: gradeLevel.value,
                strand: strand.value
            });

            fetch('../controllers/ajax_get_sections_by_grade.php?' + query.toString(), {
                headers: { Accept: 'application/json' }
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Section request failed');
                    }
                    return response.json();
                })
                .then(data => {
                    if (requestId !== sectionRequestId) {
                        return;
                    }
                    section.innerHTML = '<option value="">Select section</option>';
                    data.forEach(value => {
                        const option = document.createElement('option');
                        option.value = value;
                        option.textContent = value;
                        section.appendChild(option);
                    });
                    if (data.includes(previousSection)) {
                        section.value = previousSection;
                    }
                    section.disabled = data.length === 0;
                    if (data.length === 0) {
                        section.setAttribute('aria-invalid', 'true');
                    }
                    sectionStatus.textContent = data.length === 0 ? 'No sections are available for this target group.' : '';
                    updatePreview();
                })
                .catch(() => {
                    if (requestId !== sectionRequestId) {
                        return;
                    }
                    section.innerHTML = '<option value="">Unable to load sections</option>';
                    section.disabled = true;
                    section.setAttribute('aria-invalid', 'true');
                    sectionStatus.textContent = 'Sections could not be loaded. Try changing the grade or strand.';
                    updatePreview();
                });
        }

        gradeLevel.addEventListener('change', loadSections);
        strand.addEventListener('change', loadSections);
        section.addEventListener('change', updatePreview);
        title.addEventListener('input', updatePreview);
        description.addEventListener('input', updatePreview);
    </script>
</body>
</html>

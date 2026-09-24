<?php

require_once '../config/database.php';
require_once '../libs/assignment_management.php';

requireLogin();

if (getUserRole() !== 'teacher') {
    header('Location: /session_expired.php');
    exit();
}

$teacherId = (int) $_SESSION['user_id'];
$teacherName = $_SESSION['user_name'] ?? 'Teacher';
$teacherSubject = $_SESSION['user_subject'] ?? '';
$flash = assignment_take_flash();
$perPage = 8;
$requestedPage = assignment_id($_GET['page'] ?? null) ?? 1;
$conn = getDBConnection();

$countStmt = $conn->prepare('SELECT COUNT(*) FROM posted_assignments WHERE teacher_id = ?');
$countStmt->execute([$teacherId]);
$totalAssignments = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalAssignments / $perPage));
$page = min(max(1, $requestedPage), $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $conn->prepare(
    "SELECT id, title, description, file_path, grade_level, strand, section, created_at
     FROM posted_assignments
     WHERE teacher_id = ?
     ORDER BY created_at DESC, id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute([$teacherId]);
$assignments = $stmt->get_result()->fetch_all();

require_once __DIR__ . '/nav.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Posted Assignments | EduPortal LMS</title>
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
                    <h1>Posted Assignments</h1>
                    <p>Review, update, or remove assignments for <strong><?php echo htmlspecialchars($teacherSubject, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
                </div>
                <div class="top-bar-actions">
                    <a href="post_assignment.php" class="premium-btn premium-btn-primary">
                        <i class="fas fa-plus" aria-hidden="true"></i> Post Assignment
                    </a>
                </div>
            </header>

            <?php if ($flash): ?>
                <div class="alert <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-danger'; ?>" role="<?php echo $flash['type'] === 'success' ? 'status' : 'alert'; ?>" aria-live="<?php echo $flash['type'] === 'success' ? 'polite' : 'assertive'; ?>">
                    <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>" aria-hidden="true"></i>
                    <div><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            <?php endif; ?>

            <section class="assignment-management-intro" aria-labelledby="assignment-list-heading">
                <div>
                    <span class="assignment-eyebrow">Assignment library</span>
                    <h2 id="assignment-list-heading">Everything you have shared</h2>
                    <p><?php echo $totalAssignments; ?> <?php echo $totalAssignments === 1 ? 'assignment' : 'assignments'; ?> posted by you.</p>
                </div>
                <div class="assignment-intro-mark" aria-hidden="true">
                    <i class="fas fa-layer-group"></i>
                </div>
            </section>

            <?php if (!$assignments): ?>
                <section class="assignment-empty glass-card" aria-labelledby="empty-heading">
                    <div class="assignment-empty-icon" aria-hidden="true">
                        <i class="fas fa-folder-open"></i>
                    </div>
                    <h2 id="empty-heading">Your assignment library is empty</h2>
                    <p>Post your first assignment to give students a clear next step.</p>
                    <a href="post_assignment.php" class="premium-btn premium-btn-primary">
                        <i class="fas fa-paper-plane" aria-hidden="true"></i> Post Assignment
                    </a>
                </section>
            <?php else: ?>
                <section class="assignment-list" aria-label="Posted assignments">
                    <?php foreach ($assignments as $assignment): ?>
                        <?php
                        $assignmentId = (int) $assignment['id'];
                        $title = (string) $assignment['title'];
                        $description = (string) ($assignment['description'] ?? '');
                        $createdAt = strtotime((string) ($assignment['created_at'] ?? ''));
                        $createdAt = $createdAt === false ? time() : $createdAt;
                        $fileName = basename(str_replace('\\', '/', (string) $assignment['file_path']));
                        ?>
                        <article class="assignment-row glass-card" aria-labelledby="assignment-title-<?php echo $assignmentId; ?>">
                            <div class="assignment-row__top">
                                <div class="assignment-file-mark" aria-hidden="true">
                                    <i class="fas fa-file-lines"></i>
                                </div>
                                <div class="assignment-row__heading">
                                    <span class="assignment-eyebrow"><?php echo date('M j, Y', $createdAt); ?> · #<?php echo $assignmentId; ?></span>
                                    <h3 id="assignment-title-<?php echo $assignmentId; ?>"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <div class="assignment-target" aria-label="Target group">
                                        <span class="premium-badge badge-blue"><?php echo htmlspecialchars($assignment['grade_level'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="premium-badge badge-yellow"><?php echo htmlspecialchars($assignment['strand'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="assignment-target__section">Section <?php echo htmlspecialchars($assignment['section'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                </div>
                            </div>

                            <?php if ($description !== ''): ?>
                                <p class="assignment-row__description"><?php echo nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8'), false); ?></p>
                            <?php else: ?>
                                <p class="assignment-row__description assignment-row__description--empty">No additional instructions were added.</p>
                            <?php endif; ?>

                            <div class="assignment-row__footer">
                                <div class="assignment-file-name">
                                    <i class="fas fa-paperclip" aria-hidden="true"></i>
                                    <span><?php echo htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="assignment-actions">
                                    <a href="edit_assignment.php?id=<?php echo $assignmentId; ?>" class="premium-btn premium-btn-outline" aria-label="Edit <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas fa-pen" aria-hidden="true"></i> Edit
                                    </a>
                                    <form method="POST" action="../controllers/manage_assignment.php" data-assignment-title="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirm('Delete “' + this.dataset.assignmentTitle + '”? This action cannot be undone.');" data-loader="true">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="assignment_id" value="<?php echo $assignmentId; ?>">
                                        <button type="submit" class="premium-btn premium-btn-outline assignment-delete-button" aria-label="Delete <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-trash" aria-hidden="true"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <?php if ($totalPages > 1): ?>
                    <nav class="assignment-pagination" aria-label="Assignment pages">
                        <?php if ($page > 1): ?>
                            <a href="assignments.php?page=<?php echo $page - 1; ?>" class="premium-btn premium-btn-outline" aria-label="Previous page">
                                <i class="fas fa-arrow-left" aria-hidden="true"></i> Previous
                            </a>
                        <?php endif; ?>
                        <span>Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                        <?php if ($page < $totalPages): ?>
                            <a href="assignments.php?page=<?php echo $page + 1; ?>" class="premium-btn premium-btn-outline" aria-label="Next page">
                                Next <i class="fas fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <script src="../assets/js/system_loader.js?v=20260924"></script>
    <script src="../assets/js/responsive_ui.js?v=20260924"></script>
    <script src="../assets/js/pwa.js"></script>
</body>
</html>

<?php
function renderTeacherNav($currentPage = 'dashboard', $teacherSubject = '', $teacherName = '') {
    $studentLabel = $teacherSubject !== '' ? $teacherSubject . ' Students' : 'My Students';
    $pages = [
        'dashboard' => ['label' => 'Dashboard', 'icon' => 'fas fa-home', 'href' => 'dashboard.php'],
        'post_assignment' => ['label' => 'Post Assignment', 'icon' => 'fas fa-upload', 'href' => 'post_assignment.php'],
        'assignments' => ['label' => 'Posted Assignments', 'icon' => 'fas fa-layer-group', 'href' => 'assignments.php'],
        'students' => ['label' => $studentLabel, 'icon' => 'fas fa-user-graduate', 'href' => 'students.php'],
        'profile' => ['label' => 'Profile', 'icon' => 'fas fa-user-circle', 'href' => 'profile.php'],
    ];
    ?>
    <aside class="sidebar" id="teacher-sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo" aria-hidden="true">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="sidebar-brand">Edu<span>Portal</span></div>
        </div>
        <nav class="sidebar-menu" aria-label="Teacher navigation">
            <ul>
                <?php foreach ($pages as $key => $page): ?>
                    <?php $isCurrent = $key === $currentPage; ?>
                    <li class="menu-item">
                        <a href="<?php echo htmlspecialchars($page['href'], ENT_QUOTES, 'UTF-8'); ?>"
                           class="menu-link<?php echo $isCurrent ? ' active' : ''; ?>"
                           <?php echo $isCurrent ? 'aria-current="page"' : ''; ?>>
                            <i class="<?php echo htmlspecialchars($page['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($page['label'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <div class="user-snippet">
                <div class="avatar-small" aria-hidden="true">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="user-snippet-info">
                    <div class="user-name"><?php echo htmlspecialchars($teacherName, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="user-status"><i class="fas fa-circle" aria-hidden="true" style="font-size: 0.5rem"></i> Online</div>
                </div>
            </div>
            <form method="POST" action="../logout.php" style="display:inline;" onsubmit="return EduPortal.confirmLogout(this)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="logout-link">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i> Logout
                </button>
            </form>
        </div>
    </aside>
<?php
}

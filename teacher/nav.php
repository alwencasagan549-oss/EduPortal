<?php
require_once __DIR__ . '/../libs/navigation.php';

function renderTeacherNav($currentPage = 'dashboard', $teacherSubject = '', $teacherName = '')
{
    $studentLabel = $teacherSubject !== '' ? $teacherSubject . ' Students' : 'My Students';
    $pages = [
        'dashboard' => ['label' => 'Dashboard', 'icon' => 'fas fa-home', 'href' => 'dashboard.php'],
        'post_assignment' => ['label' => 'Post Assignment', 'icon' => 'fas fa-upload', 'href' => 'post_assignment.php'],
        'assignments' => ['label' => 'Posted Assignments', 'icon' => 'fas fa-layer-group', 'href' => 'assignments.php'],
        'students' => ['label' => $studentLabel, 'icon' => 'fas fa-user-graduate', 'href' => 'students.php'],
        'profile' => ['label' => 'Profile', 'icon' => 'fas fa-user-circle', 'href' => 'profile.php'],
    ];

    renderPortalNav($pages, $currentPage, [
        'sidebar_id' => 'teacher-sidebar',
        'name' => $teacherName,
        'status' => 'Online',
        'avatar_icon' => 'fas fa-user-tie',
        'status_icon' => 'fas fa-circle',
        'logout_icon' => 'fas fa-sign-out-alt',
    ], 'Teacher navigation');
}

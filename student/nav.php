<?php
require_once __DIR__ . '/../libs/navigation.php';

function renderStudentNav($currentPage = 'dashboard')
{
    $pages = [
        'dashboard' => ['label' => 'Dashboard', 'icon' => 'fas fa-house', 'href' => 'dashboard.php'],
        'assignments' => ['label' => 'New Assignments', 'icon' => 'fas fa-file-arrow-down', 'href' => 'assignments.php'],
        'profile' => ['label' => 'Profile', 'icon' => 'fas fa-user-circle', 'href' => 'profile.php'],
    ];

    renderPortalNav($pages, $currentPage, [
        'sidebar_id' => 'student-sidebar',
        'name' => $_SESSION['user_name'] ?? 'Student',
        'status' => 'Student',
        'avatar_icon' => 'fas fa-graduation-cap',
        'status_icon' => 'fas fa-circle',
        'logout_icon' => 'fas fa-right-from-bracket',
    ], 'Student navigation');
}

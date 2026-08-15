<?php
/**
 * Admin Deletion Controller
 */
require_once __DIR__ . '/../config/database.php';

// Check if admin is logged in
if (!isAdminLoggedIn()) {
    header('Location: ../admin/login.php');
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$id = intval($_GET['id']);
$conn = getDBConnection();

// Get file path first
$stmt = $conn->prepare("SELECT file_path FROM submissions WHERE id = ?");
$stmt->execute([$id]);
$result = $stmt->get_result();

if ($result->num_rows() === 1) {
    $submission = $result->fetch_assoc();

    if (file_exists($submission['file_path'])) {
        unlink($submission['file_path']);
    }

    $stmt = $conn->prepare("DELETE FROM submissions WHERE id = ?");
    $stmt->execute([$id]);
}

header('Location: ../admin/dashboard.php?deleted=1');
exit();
?>
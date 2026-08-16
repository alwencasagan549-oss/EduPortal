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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validate_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: ../admin/dashboard.php');
    exit();
}

if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$id = intval($_POST['id']);
$conn = getDBConnection();

// Get file path first
$stmt = $conn->prepare("SELECT file_path FROM submissions WHERE id = ?");
$stmt->execute([$id]);
$result = $stmt->get_result();

if ($result->num_rows() === 1) {
    $submission = $result->fetch_assoc();
    $file_path = $submission['file_path'];

    // Path traversal guard
    $base_dir = realpath(__DIR__ . '/../uploads');
    $real_path = realpath($file_path);
    if ($real_path !== false && strpos($real_path, $base_dir) === 0 && file_exists($real_path)) {
        unlink($real_path);
    }

    $stmt = $conn->prepare("DELETE FROM submissions WHERE id = ?");
    $stmt->execute([$id]);
}

header('Location: ../admin/dashboard.php?deleted=1');
exit();
?>
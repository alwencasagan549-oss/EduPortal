<?php
/**
 * AJAX Handler: Get sections for a specific grade level.
 * Returns only sections that have students assigned.
 */

require_once '../config/database.php';
// session_start() handled by database.php
requireLogin();

header('Content-Type: application/json');

if (getUserRole() !== 'teacher') {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

$grade_level = $_GET['grade_level'] ?? '';

if (empty($grade_level)) {
    echo json_encode([]);
    exit();
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT DISTINCT section FROM students WHERE grade_level = ? ORDER BY section");
$stmt->bind_param("s", $grade_level);
$stmt->execute();
$result = $stmt->get_result();

$sections = [];
while ($row = $result->fetch_assoc()) {
    $sections[] = $row['section'];
}

$stmt->close();
$conn->close();

echo json_encode($sections);
?>

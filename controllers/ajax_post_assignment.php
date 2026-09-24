<?php

require_once __DIR__ . '/../libs/NotificationManager.php';
require_once __DIR__ . '/../libs/assignment_management.php';

requireLogin();

header('Content-Type: application/json');

if (getUserRole() !== 'teacher') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit();
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!is_string($csrfToken) || !validate_csrf($csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
    exit();
}

$storedUpload = null;

try {
    $teacherId = (int) ($_SESSION['user_id'] ?? 0);
    $teacherName = isset($_SESSION['user_name']) && is_string($_SESSION['user_name'])
        ? $_SESSION['user_name']
        : 'Teacher';
    $subject = isset($_SESSION['user_subject']) && is_string($_SESSION['user_subject'])
        ? $_SESSION['user_subject']
        : '';
    $values = assignment_form_values($_POST);

    if (!isset($_FILES['assignment_file']) || !is_array($_FILES['assignment_file'])) {
        echo json_encode(['success' => false, 'error' => 'Please select a valid file.']);
        exit();
    }

    $file = $_FILES['assignment_file'];
    $uploadError = assignment_upload_error($file);
    if ($uploadError !== null) {
        echo json_encode(['success' => false, 'error' => $uploadError]);
        exit();
    }

    $fileData = file_get_contents($file['tmp_name']);
    if ($fileData === false) {
        echo json_encode(['success' => false, 'error' => 'The uploaded file could not be read.']);
        exit();
    }

    $storedUpload = assignment_store_upload($file);
    $fileContent = base64_encode($fileData);
    $fileType = $storedUpload['type'];
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        'INSERT INTO posted_assignments
         (teacher_id, teacher_name, subject, title, description, file_path, file_content, file_type, grade_level, strand, section)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $teacherId,
        $teacherName,
        $subject,
        $values['title'],
        $values['description'],
        $storedUpload['path'],
        $fileContent,
        $fileType,
        $values['grade_level'],
        $values['strand'],
        $values['section']
    ]);

    $studentsStmt = $conn->prepare('SELECT id, name FROM students WHERE grade_level = ? AND strand = ? AND section = ?');
    $studentsStmt->execute([$values['grade_level'], $values['strand'], $values['section']]);
    $studentsResult = $studentsStmt->get_result();
    $notifiedCount = 0;

    while ($student = $studentsResult->fetch_assoc()) {
        NotificationManager::push($student['id'], 'assignment', 'New Assignment Posted', 'Your teacher has posted a new assignment: ' . $values['title'], [
            'assignment_title' => $values['title'],
            'teacher_name' => $teacherName,
            'subject' => $subject,
            'grade_level' => $values['grade_level'],
            'section' => $values['section'],
            'strand' => $values['strand']
        ]);
        $notifiedCount++;
    }

    echo json_encode([
        'success' => true,
        'total_notified' => $notifiedCount,
        'target_group' => $values['grade_level'] . ' ' . $values['strand'] . ' - ' . $values['section']
    ]);
} catch (Throwable $exception) {
    if ($storedUpload !== null) {
        assignment_remove_stored_file($storedUpload['path']);
    }
    error_log('EduPortal error in ajax_post_assignment.php: ' . $exception->getMessage());
    echo json_encode(['success' => false, 'error' => 'The assignment could not be published. Try again.']);
}

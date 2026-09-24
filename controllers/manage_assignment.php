<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/assignment_management.php';

requireLogin();

if (getUserRole() !== 'teacher') {
    header('Location: /session_expired.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!is_string($csrfToken) || !validate_csrf($csrfToken)) {
    assignment_flash('error', 'Your security token expired. Refresh the page and try again.');
    assignment_redirect('../teacher/assignments.php');
}

$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
$assignmentId = assignment_id($_POST['assignment_id'] ?? null);

if (!in_array($action, ['update', 'delete'], true) || $assignmentId === null) {
    assignment_flash('error', 'The assignment request was invalid. Refresh the page and try again.');
    assignment_redirect('../teacher/assignments.php');
}

$teacherId = (int) $_SESSION['user_id'];
$conn = getDBConnection();
$draftValues = null;
$storedUpload = null;
$uploadCommitted = false;

try {
    $ownedStmt = $conn->prepare('SELECT id, file_path, grade_level, strand, section FROM posted_assignments WHERE id = ? AND teacher_id = ?');
    $ownedStmt->execute([$assignmentId, $teacherId]);
    $assignment = $ownedStmt->get_result()->fetch_assoc();

    if (!$assignment) {
        assignment_flash('error', 'That assignment was not found or is no longer available.');
        assignment_redirect('../teacher/assignments.php');
    }

    if ($action === 'delete') {
        $pdo = $conn->getPDO();
        $pdo->beginTransaction();

        try {
            $deleteStmt = $conn->prepare('DELETE FROM posted_assignments WHERE id = ? AND teacher_id = ? RETURNING file_path');
            $deleteStmt->execute([$assignmentId, $teacherId]);
            $deletedPath = $deleteStmt->fetchColumn();

            if ($deletedPath === false) {
                $pdo->rollBack();
                assignment_flash('error', 'That assignment was not found or is no longer available.');
                assignment_redirect('../teacher/assignments.php');
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        assignment_remove_stored_file((string) $deletedPath);
        assignment_clear_draft($assignmentId);
        assignment_flash('success', 'The posted assignment was deleted.');
        assignment_redirect('../teacher/assignments.php');
    }

    $values = assignment_form_values($_POST);
    $draftValues = $values;
    $errors = assignment_validate($values);
    $targetChanged = $values['grade_level'] !== (string) ($assignment['grade_level'] ?? '')
        || $values['strand'] !== (string) ($assignment['strand'] ?? '')
        || $values['section'] !== (string) ($assignment['section'] ?? '');

    if ($targetChanged && !$errors) {
        $targetStmt = $conn->prepare('SELECT 1 FROM students WHERE grade_level = ? AND strand = ? AND section = ? LIMIT 1');
        $targetStmt->execute([$values['grade_level'], $values['strand'], $values['section']]);
        if ($targetStmt->fetchColumn() === false) {
            $errors[] = 'Choose a target group that has at least one student.';
        }
    }

    $replacement = isset($_FILES['assignment_file'])
        && is_array($_FILES['assignment_file'])
        && (int) ($_FILES['assignment_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($replacement) {
        $uploadError = assignment_upload_error($_FILES['assignment_file']);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }
    }

    if ($errors) {
        assignment_store_draft($assignmentId, $values);
        assignment_flash('error', implode(' ', $errors));
        assignment_redirect('../teacher/edit_assignment.php?id=' . $assignmentId);
    }

    if ($replacement) {
        try {
            $storedUpload = assignment_store_upload($_FILES['assignment_file']);
        } catch (Throwable $exception) {
            error_log('EduPortal assignment replacement error: ' . $exception->getMessage());
            assignment_store_draft($assignmentId, $values);
            assignment_flash('error', 'The replacement file could not be saved. Try again or keep the current file.');
            assignment_redirect('../teacher/edit_assignment.php?id=' . $assignmentId);
        }
    }

    $teacherName = isset($_SESSION['user_name']) && is_string($_SESSION['user_name'])
        ? $_SESSION['user_name']
        : 'Teacher';
    $teacherSubject = isset($_SESSION['user_subject']) && is_string($_SESSION['user_subject'])
        ? $_SESSION['user_subject']
        : '';
    $pdo = $conn->getPDO();
    $pdo->beginTransaction();

    try {
        $setParts = ['teacher_name = ?', 'subject = ?', 'title = ?', 'description = ?'];
        $params = [$teacherName, $teacherSubject, $values['title'], $values['description']];

        if ($storedUpload !== null) {
            $storageColumns = assignment_storage_columns($conn);
            $setParts[] = 'file_path = ?';
            $params[] = $storedUpload['path'];
            if (isset($storageColumns['file_content'])) {
                $setParts[] = 'file_content = NULL';
            }
            if (isset($storageColumns['file_type'])) {
                $setParts[] = 'file_type = ?';
                $params[] = $storedUpload['type'];
            }
        }

        $setParts[] = 'grade_level = ?';
        $setParts[] = 'strand = ?';
        $setParts[] = 'section = ?';
        $params[] = $values['grade_level'];
        $params[] = $values['strand'];
        $params[] = $values['section'];
        $params[] = $assignmentId;
        $params[] = $teacherId;
        $updateStmt = $conn->prepare(
            'UPDATE posted_assignments SET ' . implode(', ', $setParts) . ' WHERE id = ? AND teacher_id = ? RETURNING id'
        );
        $updateStmt->execute($params);

        if ($updateStmt->fetchColumn() === false) {
            $pdo->rollBack();
            if ($storedUpload !== null) {
                assignment_remove_stored_file($storedUpload['path']);
            }
            assignment_flash('error', 'That assignment was not found or is no longer available.');
            assignment_redirect('../teacher/assignments.php');
        }

        $pdo->commit();
        $uploadCommitted = true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($storedUpload !== null) {
            assignment_remove_stored_file($storedUpload['path']);
        }
        throw $exception;
    }

    if ($storedUpload !== null) {
        assignment_remove_stored_file($assignment['file_path']);
    }

    assignment_clear_draft($assignmentId);
    assignment_flash('success', 'The posted assignment was updated.');
    assignment_redirect('../teacher/assignments.php');
} catch (Throwable $exception) {
    error_log('EduPortal assignment action error: ' . $exception->getMessage());
    if ($action === 'update' && is_array($draftValues)) {
        assignment_store_draft($assignmentId, $draftValues);
    }
    if (!$uploadCommitted && $storedUpload !== null) {
        assignment_remove_stored_file($storedUpload['path']);
    }
    assignment_flash('error', 'The assignment could not be changed. Try again.');
    assignment_redirect($action === 'update'
        ? '../teacher/edit_assignment.php?id=' . $assignmentId
        : '../teacher/assignments.php');
}

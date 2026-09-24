<?php
/**
 * Secure Assignment Submission with RLS
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../libs/assignment_management.php';

if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'student') {
    header('Location: ../student/login.php');
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../student/dashboard.php?error=Submission+method+is+not+allowed', true, 303);
    exit();
}

if (!validate_csrf($_POST['csrf_token'] ?? '')) {
    header('Location: ../student/dashboard.php?error=Invalid+security+token', true, 303);
    exit();
}

$studentId = assignment_id($_SESSION['user_id'] ?? null);
$studentName = isset($_SESSION['user_name']) && is_string($_SESSION['user_name'])
    ? $_SESSION['user_name']
    : '';
$subject = assignment_normalize_subject($_POST['subject'] ?? '');
$rawAssignmentId = $_POST['assignment_id'] ?? $_POST['posted_assignment_id'] ?? null;
$assignmentId = assignment_id($rawAssignmentId);

if ($rawAssignmentId !== null && $rawAssignmentId !== '' && $assignmentId === null) {
    header('Location: ../student/dashboard.php?error=Invalid+assignment+selection', true, 303);
    exit();
}

$conn = getDBConnection();
$gradeLevel = $_SESSION['user_grade'] ?? '';
$section = $_SESSION['user_section'] ?? '';
$strand = $_SESSION['user_strand'] ?? 'Academic';

$postedAssignment = null;

if ($assignmentId !== null) {
    $assignmentStmt = $conn->prepare(
        'SELECT id, subject, grade_level, strand, section, teacher_id
         FROM posted_assignments
         WHERE id = ? AND grade_level = ? AND strand = ? AND section = ?'
    );
    $assignmentStmt->execute([$assignmentId, $gradeLevel, $strand, $section]);
    $postedAssignment = $assignmentStmt->fetch_assoc();

    if (!$postedAssignment) {
        header('Location: ../student/dashboard.php?error=That+assignment+is+not+available+for+your+class', true, 303);
        exit();
    }

    $subject = assignment_normalize_subject($postedAssignment['subject'] ?? '');
}

if ($studentId === null || $studentName === '' || ($assignmentId === null && $subject === '')) {
    header('Location: ../student/dashboard.php?error=All+fields+are+required', true, 303);
    exit();
}

if (assignment_subject_length($subject) > 255) {
    header('Location: ../student/dashboard.php?error=Subject+name+is+too+long', true, 303);
    exit();
}

if (!isset($_FILES['assignment']) || !is_array($_FILES['assignment']) || ($_FILES['assignment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    header('Location: ../student/dashboard.php?error=Please+select+a+file', true, 303);
    exit();
}

$file = $_FILES['assignment'];
$allowedExtensions = ['pdf', 'doc', 'docx'];
$maxSize = 10 * 1024 * 1024;
$fileExtension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
$fileSize = isset($file['size']) ? (int) $file['size'] : 0;

if (!in_array($fileExtension, $allowedExtensions, true)) {
    header('Location: ../student/dashboard.php?error=Only+PDF,+DOC,+and+DOCX+files+are+allowed', true, 303);
    exit();
}

if ($fileSize < 1 || $fileSize > $maxSize) {
    header('Location: ../student/dashboard.php?error=File+size+exceeds+10MB+limit', true, 303);
    exit();
}

$submissionSchemaReady = assignment_ensure_submission_schema($conn);
if ($assignmentId !== null && (!$submissionSchemaReady || !assignment_submission_has_column($conn, 'assignment_id'))) {
    header('Location: ../student/dashboard.php?error=Submission+storage+is+not+ready', true, 303);
    exit();
}
if (!$submissionSchemaReady) {
    try {
        $knownSubmissionColumns = assignment_submission_column_names($conn);
        foreach (['student_id', 'subject', 'file_path', 'submission_date'] as $requiredColumn) {
            if (!isset($knownSubmissionColumns[$requiredColumn])) {
                throw new RuntimeException('Required submission column is missing: ' . $requiredColumn);
            }
        }
    } catch (Throwable $exception) {
        error_log('EduPortal submission storage verification failed: ' . $exception->getMessage());
        header('Location: ../student/dashboard.php?error=Submission+storage+is+not+ready', true, 303);
        exit();
    }
}

$uploadDirectory = __DIR__ . '/../uploads';
if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
    header('Location: ../student/dashboard.php?error=Upload+storage+is+not+available', true, 303);
    exit();
}

$newFilename = date('Ymd_His') . '_' . bin2hex(random_bytes(12)) . '.' . $fileExtension;
$uploadPath = 'uploads/' . $newFilename;
$absoluteUploadPath = $uploadDirectory . DIRECTORY_SEPARATOR . $newFilename;

if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $absoluteUploadPath)) {
    header('Location: ../student/dashboard.php?error=Failed+to+save+file', true, 303);
    exit();
}

$fileData = file_get_contents($absoluteUploadPath);
if (!function_exists('finfo_open') && !function_exists('mime_content_type')) {
    $fileRemoved = assignment_remove_stored_file($uploadPath);
    if (!assignment_record_deletion_audit($conn, [
        'event' => 'submission_file_deleted',
        'reason' => 'mime_support_unavailable',
        'student_id' => $studentId,
        'assignment_id' => $assignmentId,
        'subject' => $subject,
        'file_path' => $uploadPath,
        'file_removed' => $fileRemoved,
        'actor_id' => $studentId
    ])) {
        error_log('EduPortal MIME-support audit persistence failed: ' . $uploadPath);
    }
    header('Location: ../student/dashboard.php?error=File+verification+is+temporarily+unavailable', true, 303);
    exit();
}
$verifiedMime = '';
if ($fileData !== false) {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $verifiedMime = (string) finfo_file($finfo, $absoluteUploadPath);
            finfo_close($finfo);
        }
    } elseif (function_exists('mime_content_type')) {
        $verifiedMime = (string) mime_content_type($absoluteUploadPath);
    }
}

$allowedMimes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

if ($fileData === false || $verifiedMime === '' || !in_array($verifiedMime, $allowedMimes, true)) {
    $fileRemoved = assignment_remove_stored_file($uploadPath);
    if (!assignment_record_deletion_audit($conn, [
        'event' => 'submission_file_deleted',
        'reason' => 'invalid_file_type',
        'student_id' => $studentId,
        'assignment_id' => $assignmentId,
        'subject' => $subject,
        'file_path' => $uploadPath,
        'file_removed' => $fileRemoved,
        'actor_id' => $studentId
    ])) {
        error_log('EduPortal invalid-upload audit persistence failed: ' . $uploadPath);
    }
    header('Location: ../student/dashboard.php?error=Invalid+file+type.+Please+upload+a+PDF+or+Word+document.', true, 303);
    exit();
}

$fileContent = base64_encode($fileData);
unset($fileData);
$fileContentForStorage = $fileContent;
if (assignment_driver_name($conn) === 'mysql' && strlen($fileContent) > 3 * 1024 * 1024) {
    $fileContentForStorage = null;
}
$currentDate = date('Y-m-d');
$submissionColumns = [];
$duplicateDetails = [
    'student_id' => $studentId,
    'assignment_id' => $assignmentId,
    'subject' => $subject,
    'file_path' => $uploadPath,
    'actor_id' => $studentId
];

$discardLockedDuplicate = static function ($pdo, ?int $existingSubmissionId) use ($conn, $uploadPath, $duplicateDetails): void {
    if ($existingSubmissionId === null) {
        return;
    }

    $details = $duplicateDetails;
    $details['event'] = 'duplicate_upload_discarded';
    $details['existing_submission_id'] = $existingSubmissionId;
    $details['file_removed'] = assignment_remove_stored_file($uploadPath);
    $pdo->rollBack();
    if (!assignment_record_deletion_audit($conn, $details)) {
        error_log('EduPortal duplicate upload audit persistence failed: ' . $uploadPath);
    }
    header('Location: ../student/dashboard.php?duplicate=1', true, 303);
    exit();
};

try {
    try {
        $submissionColumns = assignment_submission_column_names($conn);
    } catch (Throwable $exception) {
        error_log('EduPortal submission column discovery failed: ' . $exception->getMessage());
        $submissionColumns = [];
    }
    if ($assignmentId !== null && !isset($submissionColumns['assignment_id'])) {
        throw new RuntimeException('Assignment identity column is unavailable.');
    }

    $insertFields = ['student_id', 'subject', 'file_path', 'submission_date'];
    $insertValues = [$studentId, $subject, $uploadPath, $currentDate];
    if (isset($submissionColumns['file_content']) && $fileContentForStorage !== null) {
        $insertFields[] = 'file_content';
        $insertValues[] = $fileContentForStorage;
    }
    if (isset($submissionColumns['file_type'])) {
        $insertFields[] = 'file_type';
        $insertValues[] = $verifiedMime;
    }
    if ($assignmentId !== null && isset($submissionColumns['assignment_id'])) {
        $insertFields[] = 'assignment_id';
        $insertValues[] = $assignmentId;
    }
    if ($assignmentId !== null && $postedAssignment !== null && isset($submissionColumns['teacher_id'])) {
        $insertFields[] = 'teacher_id';
        $insertValues[] = $postedAssignment['teacher_id'];
    }

    $placeholders = implode(', ', array_fill(0, count($insertFields), '?'));
    $insertSql = 'INSERT INTO submissions (' . implode(', ', $insertFields) . ') VALUES (' . $placeholders . ')';
    $pdo = $conn->getPDO();
    $pdo->beginTransaction();

    try {
        assignment_lock_student_submissions($conn, $studentId);
        if ($assignmentId !== null) {
            $postedAssignment = assignment_lock_posted_assignment($conn, $assignmentId, $gradeLevel, $strand, $section);
            $subject = assignment_normalize_subject($postedAssignment['subject'] ?? '');
            if (assignment_subject_length($subject) > 255) {
                throw new RuntimeException('The posted assignment subject is too long.');
            }
            $duplicateDetails['subject'] = $subject;
            $insertValues[1] = $subject;
            $teacherFieldIndex = array_search('teacher_id', $insertFields, true);
            if ($teacherFieldIndex !== false) {
                $insertValues[$teacherFieldIndex] = $postedAssignment['teacher_id'] ?? null;
            }
        }
        $existingSubmissionId = assignment_find_submission($conn, $studentId, $assignmentId, $subject, true);
        if ($existingSubmissionId !== null) {
            $discardLockedDuplicate($pdo, $existingSubmissionId);
        }

        $stmt = $conn->prepare($insertSql);
        $stmt->execute($insertValues);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (assignment_is_duplicate_database_error($exception)) {
            $conflictPdo = $conn->getPDO();
            $conflictPdo->beginTransaction();
            try {
                assignment_lock_student_submissions($conn, $studentId);
                if ($assignmentId !== null) {
                    $postedAssignment = assignment_lock_posted_assignment($conn, $assignmentId, $gradeLevel, $strand, $section);
                    $subject = assignment_normalize_subject($postedAssignment['subject'] ?? '');
                    $duplicateDetails['subject'] = $subject;
                    $insertValues[1] = $subject;
                    $teacherFieldIndex = array_search('teacher_id', $insertFields, true);
                    if ($teacherFieldIndex !== false) {
                        $insertValues[$teacherFieldIndex] = $postedAssignment['teacher_id'] ?? null;
                    }
                }
                $existingSubmissionId = assignment_find_submission($conn, $studentId, $assignmentId, $subject, true);
                if ($existingSubmissionId !== null) {
                    $discardLockedDuplicate($conflictPdo, $existingSubmissionId);
                }

                $retryStmt = $conn->prepare($insertSql);
                $retryStmt->execute($insertValues);
                $conflictPdo->commit();
                header('Location: ../student/dashboard.php?success=1', true, 303);
                exit();
            } catch (Throwable $lookupException) {
                if ($conflictPdo->inTransaction()) {
                    $conflictPdo->rollBack();
                }
                error_log('EduPortal duplicate submission conflict retry failed: ' . $lookupException->getMessage());
            }
        }
        throw $exception;
    }
} catch (Throwable $exception) {
    $fileRemoved = assignment_remove_stored_file($uploadPath);
    if (!assignment_record_deletion_audit($conn, [
        'event' => 'submission_file_deleted',
        'reason' => 'submission_failed',
        'student_id' => $studentId,
        'assignment_id' => $assignmentId,
        'subject' => $subject,
        'file_path' => $uploadPath,
        'file_removed' => $fileRemoved,
        'actor_id' => $studentId
    ])) {
        error_log('EduPortal failed-submission audit persistence failed: ' . $uploadPath);
    }
    error_log('EduPortal Submission Error: ' . $exception->getMessage());
    header('Location: ../student/dashboard.php?error=Database+error.+Please+try+again.', true, 303);
    exit();
}

header('Location: ../student/dashboard.php?success=1', true, 303);
exit();

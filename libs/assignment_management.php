<?php

function assignment_flash(string $type, string $message): void
{
    $type = in_array($type, ['success', 'error'], true) ? $type : 'error';
    $_SESSION['assignment_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function assignment_take_flash(): ?array
{
    if (!isset($_SESSION['assignment_flash']) || !is_array($_SESSION['assignment_flash'])) {
        return null;
    }

    $flash = $_SESSION['assignment_flash'];
    unset($_SESSION['assignment_flash']);

    return $flash;
}

function assignment_redirect(string $location): void
{
    header('Location: ' . $location, true, 303);
    exit;
}

function assignment_id($value): ?int
{
    if (is_int($value)) {
        $id = $value;
    } elseif (is_string($value) && ctype_digit($value)) {
        $id = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647]
        ]);
    } else {
        return null;
    }

    if ($id === false || $id < 1) {
        return null;
    }

    return (int) $id;
}

function assignment_form_values(array $input): array
{
    $fields = ['grade_level', 'strand', 'section', 'title', 'description'];
    $values = [];

    foreach ($fields as $field) {
        $values[$field] = isset($input[$field]) && is_string($input[$field])
            ? trim($input[$field])
            : '';
    }

    return $values;
}

function assignment_validate(array $values): array
{
    $errors = [];
    $title = $values['title'] ?? '';
    $description = $values['description'] ?? '';
    $gradeLevel = $values['grade_level'] ?? '';
    $strand = $values['strand'] ?? '';
    $section = $values['section'] ?? '';

    if (!is_string($title) || $title === '') {
        $errors[] = 'Enter an assignment title.';
    } elseif (strlen($title) > 255) {
        $errors[] = 'The assignment title must be 255 characters or fewer.';
    }

    if (!is_string($description) || strlen($description) > 20000) {
        $errors[] = 'The instructions must be 20,000 characters or fewer.';
    }

    if (!is_string($gradeLevel) || $gradeLevel === '' || strlen($gradeLevel) > 50) {
        $errors[] = 'Choose a valid target grade level.';
    }

    if (!is_string($strand) || !in_array($strand, ['Academic', 'Tech-pro'], true)) {
        $errors[] = 'Choose a valid target strand.';
    }

    if (!is_string($section) || $section === '' || strlen($section) > 50) {
        $errors[] = 'Choose a valid target section.';
    }

    return $errors;
}

function assignment_upload_rules(): array
{
    return [
        'max_bytes' => 9 * 1024 * 1024,
        'extensions' => [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/x-ole-storage'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
                'application/x-zip-compressed'
            ],
            'txt' => ['text/plain'],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'mp4' => ['video/mp4', 'application/mp4']
        ]
    ];
}

function assignment_storage_columns($conn): array
{
    $stmt = $conn->prepare(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = 'posted_assignments'
           AND column_name IN ('file_content', 'file_type')"
    );
    $stmt->execute();
    $columns = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $columns[(string) $row['column_name']] = true;
    }
    return $columns;
}

function assignment_upload_error(array $file): ?string
{
    $rules = assignment_upload_rules();
    $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        return 'The replacement file could not be uploaded. Choose a file and try again.';
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return 'The replacement file could not be verified.';
    }

    $size = isset($file['size']) ? (int) $file['size'] : 0;
    if ($size < 1 || $size > $rules['max_bytes']) {
        return 'Replacement files must be smaller than 9 MB.';
    }

    $name = isset($file['name']) && is_string($file['name']) ? $file['name'] : '';
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!isset($rules['extensions'][$extension])) {
        return 'That file type is not supported for assignment files.';
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string) finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    } elseif (function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($file['tmp_name']);
    }

    if ($mime === '' || !in_array($mime, $rules['extensions'][$extension], true)) {
        return 'The replacement file type could not be verified.';
    }

    return null;
}

function assignment_store_upload(array $file): array
{
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $uploadRoot = dirname(__DIR__) . '/uploads';
    $uploadDirectory = $uploadRoot . '/assignments';

    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('The assignment upload directory is not writable.');
    }

    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $absolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
    $relativePath = 'assignments/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('The assignment file could not be stored.');
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string) finfo_file($finfo, $absolutePath);
            finfo_close($finfo);
        }
    } elseif (function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($absolutePath);
    }

    return [
        'path' => $relativePath,
        'type' => $mime !== '' ? $mime : 'application/octet-stream'
    ];
}

function assignment_remove_stored_file($filePath): bool
{
    if (!is_string($filePath) || trim($filePath) === '') {
        return true;
    }

    $relativePath = ltrim(str_replace('\\', '/', $filePath), '/');
    if (strpos($relativePath, 'uploads/') === 0) {
        $relativePath = substr($relativePath, strlen('uploads/'));
    }

    $uploadRoot = realpath(dirname(__DIR__) . '/uploads');
    if ($uploadRoot === false) {
        error_log('EduPortal assignment file cleanup could not resolve the upload root.');
        return false;
    }

    $resolvedPath = realpath($uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    $rootPrefix = rtrim($uploadRoot, '/\\') . DIRECTORY_SEPARATOR;

    if ($resolvedPath === false || strpos(str_replace('\\', '/', $resolvedPath), str_replace('\\', '/', $rootPrefix)) !== 0) {
        error_log('EduPortal assignment file cleanup rejected an unsafe path.');
        return false;
    }

    if (!is_file($resolvedPath)) {
        return true;
    }

    if (!@unlink($resolvedPath)) {
        error_log('EduPortal assignment file cleanup could not remove the stored file.');
        return false;
    }

    return true;
}

function assignment_prepare_drafts(): void
{
    if (!isset($_SESSION['assignment_drafts']) || !is_array($_SESSION['assignment_drafts'])) {
        $_SESSION['assignment_drafts'] = [];
    }

    if (isset($_SESSION['assignment_draft']) && is_array($_SESSION['assignment_draft'])) {
        $legacyId = (int) ($_SESSION['assignment_draft']['id'] ?? 0);
        if ($legacyId > 0 && isset($_SESSION['assignment_draft']['values']) && is_array($_SESSION['assignment_draft']['values'])) {
            $_SESSION['assignment_drafts'][(string) $legacyId] = [
                'id' => $legacyId,
                'values' => $_SESSION['assignment_draft']['values']
            ];
        }
        unset($_SESSION['assignment_draft']);
    }
}

function assignment_store_draft(int $id, array $values): void
{
    assignment_prepare_drafts();
    $_SESSION['assignment_drafts'][(string) $id] = [
        'id' => $id,
        'values' => array_intersect_key($values, array_flip(['grade_level', 'strand', 'section', 'title', 'description']))
    ];
}

function assignment_clear_draft(int $id): void
{
    assignment_prepare_drafts();
    unset($_SESSION['assignment_drafts'][(string) $id]);
    if ($_SESSION['assignment_drafts'] === []) {
        unset($_SESSION['assignment_drafts']);
    }
}

function assignment_take_draft(int $id): ?array
{
    assignment_prepare_drafts();
    $key = (string) $id;
    if (!isset($_SESSION['assignment_drafts'][$key]) || !is_array($_SESSION['assignment_drafts'][$key])) {
        return null;
    }

    $draft = $_SESSION['assignment_drafts'][$key];
    unset($_SESSION['assignment_drafts'][$key]);
    if ($_SESSION['assignment_drafts'] === []) {
        unset($_SESSION['assignment_drafts']);
    }

    if (!isset($draft['values']) || !is_array($draft['values'])) {
        return null;
    }

    return $draft['values'];
}

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
    } elseif (assignment_subject_length($title) > 255) {
        $errors[] = 'The assignment title must be 255 characters or fewer.';
    }

    if (!is_string($description) || assignment_subject_length($description) > 20000) {
        $errors[] = 'The instructions must be 20,000 characters or fewer.';
    }

    if (!is_string($gradeLevel) || $gradeLevel === '' || assignment_subject_length($gradeLevel) > 50) {
        $errors[] = 'Choose a valid target grade level.';
    }

    if (!is_string($strand) || !in_array($strand, ['Academic', 'Tech-pro'], true)) {
        $errors[] = 'Choose a valid target strand.';
    }

    if (!is_string($section) || $section === '' || assignment_subject_length($section) > 50) {
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

function assignment_normalize_subject($subject): string
{
    if (!is_string($subject)) {
        return '';
    }

    $normalized = preg_replace('/\s+/u', ' ', trim($subject));

    return is_string($normalized) ? $normalized : trim($subject);
}

function assignment_subject_length(string $subject): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($subject, 'UTF-8');
    }

    $length = preg_match_all('/./us', $subject, $matches);
    return $length === false ? strlen($subject) : $length;
}

function assignment_submission_identity($studentId, $assignmentId, $subject): ?array
{
    $studentId = assignment_id($studentId);
    if ($studentId === null) {
        return null;
    }

    $hasAssignmentId = $assignmentId !== null && $assignmentId !== '';
    if ($hasAssignmentId) {
        $assignmentId = assignment_id($assignmentId);
        if ($assignmentId === null) {
            return null;
        }
    } else {
        $assignmentId = null;
    }

    $subject = assignment_normalize_subject($subject);
    if ($assignmentId === null && $subject === '') {
        return null;
    }

    return [
        'student_id' => $studentId,
        'assignment_id' => $assignmentId,
        'subject' => $subject
    ];
}

function assignment_db_boolean($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower((string) $value), ['1', 't', 'true'], true);
}

function assignment_driver_name($conn): string
{
    try {
        if (method_exists($conn, 'getDriverName')) {
            return strtolower((string) $conn->getDriverName());
        }
    } catch (Throwable $exception) {
        return 'pgsql';
    }

    return 'pgsql';
}

function assignment_schema_expression($conn): string
{
    return assignment_driver_name($conn) === 'mysql' ? 'DATABASE()' : 'current_schema()';
}

function assignment_submission_column_names($conn): array
{
    $schemaExpression = assignment_schema_expression($conn);
    $stmt = $conn->prepare(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = {$schemaExpression}
           AND table_name = 'submissions'"
    );
    $stmt->execute();

    $columns = [];
    while (($row = $stmt->fetch_assoc()) !== false) {
        $columns[strtolower((string) $row['column_name'])] = true;
    }

    return $columns;
}

function assignment_submission_column_type($conn, string $column): ?string
{
    try {
        $schemaExpression = assignment_schema_expression($conn);
        $stmt = $conn->prepare(
            "SELECT data_type
             FROM information_schema.columns
             WHERE table_schema = {$schemaExpression}
               AND table_name = 'submissions'
               AND column_name = ?"
        );
        $stmt->execute([$column]);
        $row = $stmt->fetch_assoc();
        return $row ? strtolower((string) $row['data_type']) : null;
    } catch (Throwable $exception) {
        error_log('EduPortal submission column type check failed: ' . $exception->getMessage());
        return null;
    }
}

function assignment_submission_subject_max_length($conn): ?int
{
    try {
        $schemaExpression = assignment_schema_expression($conn);
        $stmt = $conn->prepare(
            "SELECT character_maximum_length
             FROM information_schema.columns
             WHERE table_schema = {$schemaExpression}
               AND table_name = 'submissions'
               AND column_name = 'subject'"
        );
        $stmt->execute();
        $row = $stmt->fetch_assoc();
        if (!$row || $row['character_maximum_length'] === null) {
            return null;
        }
        return (int) $row['character_maximum_length'];
    } catch (Throwable $exception) {
        error_log('EduPortal submission subject capacity check failed: ' . $exception->getMessage());
        return -1;
    }
}

function assignment_submission_has_column($conn, string $column): bool
{
    try {
        $columns = assignment_submission_column_names($conn);
        return isset($columns[strtolower($column)]);
    } catch (Throwable $exception) {
        error_log('EduPortal submission schema check failed: ' . $exception->getMessage());
        return false;
    }
}

function assignment_submission_unique_index_exists($conn): bool
{
    $indexName = 'idx_submissions_student_assignment_unique';

    try {
        if (assignment_driver_name($conn) === 'mysql') {
            $schemaExpression = assignment_schema_expression($conn);
            $stmt = $conn->prepare(
                "SELECT non_unique, column_name
                 FROM information_schema.statistics
                 WHERE table_schema = {$schemaExpression}
                   AND table_name = 'submissions'
                   AND index_name = ?
                 ORDER BY seq_in_index"
            );
            $stmt->execute([$indexName]);
            $indexColumns = [];
            $isUnique = true;
            while (($row = $stmt->fetch_assoc()) !== false) {
                $isUnique = $isUnique && (int) $row['non_unique'] === 0;
                $indexColumns[] = strtolower((string) $row['column_name']);
            }
            return $isUnique && $indexColumns === ['student_id', 'assignment_id'];
        }

        $stmt = $conn->prepare(
            "SELECT i.indisunique, i.indisvalid, i.indisready, pg_get_indexdef(i.indexrelid) AS index_definition
             FROM pg_index i
             JOIN pg_class index_class ON index_class.oid = i.indexrelid
             JOIN pg_class table_class ON table_class.oid = i.indrelid
             JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
             WHERE table_class.relname = 'submissions'
               AND index_class.relname = ?
               AND namespace.nspname = current_schema()"
        );
        $stmt->execute([$indexName]);
        $row = $stmt->fetch_assoc();
        if (!$row
            || !assignment_db_boolean($row['indisunique'])
            || !assignment_db_boolean($row['indisvalid'])
            || !assignment_db_boolean($row['indisready'])) {
            return false;
        }

        $definition = strtolower((string) $row['index_definition']);
        if (!preg_match('/using\s+btree\s*\(([^)]*)\)/i', $definition, $matches)) {
            return false;
        }
        $columns = array_map(static function (string $column): string {
            return trim(preg_replace('/[^a-z0-9_]/i', '', $column));
        }, explode(',', $matches[1]));
        if ($columns !== ['student_id', 'assignment_id']) {
            return false;
        }
        $wherePosition = strpos($definition, ' where ');
        $predicate = $wherePosition === false ? '' : trim((string) substr($definition, $wherePosition + 7));
        return $predicate === ''
            || (bool) preg_match('/^\(?\s*assignment_id\s+is\s+not\s+null\s*\)?$/', $predicate);
    } catch (Throwable $exception) {
        error_log('EduPortal submission uniqueness index check failed: ' . $exception->getMessage());
        return false;
    }
}

function assignment_ensure_submission_schema($conn): bool
{
    try {
        $columns = assignment_submission_column_names($conn);

        if (!isset($columns['file_content'])) {
            try {
                if (assignment_driver_name($conn) === 'mysql') {
                    $conn->exec('ALTER TABLE submissions ADD COLUMN file_content LONGTEXT');
                } else {
                    $conn->exec('ALTER TABLE submissions ADD COLUMN file_content TEXT DEFAULT NULL');
                }
            } catch (Throwable $exception) {
                $columns = assignment_submission_column_names($conn);
                if (!isset($columns['file_content'])) {
                    throw $exception;
                }
            }
        } elseif (assignment_driver_name($conn) === 'mysql'
            && assignment_submission_column_type($conn, 'file_content') !== 'longtext') {
            try {
                $conn->exec('ALTER TABLE submissions MODIFY file_content LONGTEXT');
            } catch (Throwable $exception) {
                if (assignment_submission_column_type($conn, 'file_content') !== 'longtext') {
                    throw $exception;
                }
            }
        }
        if (!isset($columns['file_type'])) {
            try {
                $conn->exec("ALTER TABLE submissions ADD COLUMN file_type VARCHAR(100) DEFAULT 'application/octet-stream'");
            } catch (Throwable $exception) {
                $columns = assignment_submission_column_names($conn);
                if (!isset($columns['file_type'])) {
                    throw $exception;
                }
            }
        }
        if (!isset($columns['assignment_id'])) {
            try {
                $conn->exec('ALTER TABLE submissions ADD COLUMN assignment_id INTEGER DEFAULT NULL');
            } catch (Throwable $exception) {
                $columns = assignment_submission_column_names($conn);
                if (!isset($columns['assignment_id'])) {
                    throw $exception;
                }
            }
        }

        $subjectMaxLength = assignment_submission_subject_max_length($conn);
        if ($subjectMaxLength === -1) {
            return false;
        }
        if ($subjectMaxLength !== null && $subjectMaxLength < 255) {
            try {
                if (assignment_driver_name($conn) === 'mysql') {
                    $conn->exec('ALTER TABLE submissions MODIFY subject VARCHAR(255) NOT NULL');
                } else {
                    $conn->exec('ALTER TABLE submissions ALTER COLUMN subject TYPE VARCHAR(255)');
                }
            } catch (Throwable $exception) {
                $subjectMaxLength = assignment_submission_subject_max_length($conn);
                if ($subjectMaxLength !== null && $subjectMaxLength < 255) {
                    throw $exception;
                }
            }
        }

        if (!assignment_submission_unique_index_exists($conn)) {
            $indexSql = "CREATE UNIQUE INDEX idx_submissions_student_assignment_unique
                         ON submissions (student_id, assignment_id)";
            if (assignment_driver_name($conn) !== 'mysql') {
                $indexSql .= ' WHERE assignment_id IS NOT NULL';
            }
            try {
                $conn->exec($indexSql);
            } catch (Throwable $exception) {
                error_log('EduPortal submission uniqueness index could not be created: ' . $exception->getMessage());
            }
        }

        if (!assignment_submission_unique_index_exists($conn)) {
            return false;
        }

        if (assignment_driver_name($conn) !== 'mysql') {
            try {
                $conn->exec(
                    'CREATE INDEX IF NOT EXISTS idx_submissions_student_subject_normalized
                     ON submissions (student_id, LOWER(TRIM(subject)))
                     WHERE assignment_id IS NULL'
                );
            } catch (Throwable $exception) {
                error_log('EduPortal normalized submission index could not be created: ' . $exception->getMessage());
            }
        }

        return assignment_submission_has_column($conn, 'assignment_id');
    } catch (Throwable $exception) {
        error_log('EduPortal submission schema migration failed: ' . $exception->getMessage());
        return false;
    }
}

function assignment_lock_posted_assignment($conn, $assignmentId, $gradeLevel = null, $strand = null, $section = null): array
{
    $assignmentId = assignment_id($assignmentId);
    if ($assignmentId === null) {
        throw new RuntimeException('A valid posted assignment is required.');
    }

    if ($gradeLevel !== null && $strand !== null && $section !== null) {
        $stmt = $conn->prepare(
            'SELECT id, subject, teacher_id, grade_level, strand, section
             FROM posted_assignments
             WHERE id = ? AND grade_level = ? AND strand = ? AND section = ?
             FOR UPDATE'
        );
        $stmt->execute([$assignmentId, $gradeLevel, $strand, $section]);
    } else {
        $stmt = $conn->prepare(
            'SELECT id, subject, teacher_id, grade_level, strand, section
             FROM posted_assignments
             WHERE id = ?
             FOR UPDATE'
        );
        $stmt->execute([$assignmentId]);
    }
    $assignment = $stmt->fetch_assoc();
    if (!$assignment) {
        throw new RuntimeException('The posted assignment is no longer available for this class.');
    }

    return $assignment;
}

function assignment_lock_student_submissions($conn, $studentId): void
{
    $studentId = assignment_id($studentId);
    if ($studentId === null) {
        throw new RuntimeException('A valid student is required before checking submission duplicates.');
    }

    $stmt = $conn->prepare('SELECT id FROM students WHERE id = ? FOR UPDATE');
    $stmt->execute([$studentId]);
    if ($stmt->fetchColumn() === false) {
        throw new RuntimeException('The authenticated student could not be verified before submission.');
    }
}

function assignment_find_submission($conn, $studentId, $assignmentId, $subject, bool $forUpdate = false, bool $includeUnlinked = false): ?int
{
    $identity = assignment_submission_identity($studentId, $assignmentId, $subject);
    if ($identity === null) {
        return null;
    }

    try {
        $submissionColumns = assignment_submission_column_names($conn);
    } catch (Throwable $exception) {
        throw new RuntimeException('Submission schema could not be verified before duplicate checking.', 0, $exception);
    }
    $hasAssignmentColumn = isset($submissionColumns['assignment_id']);
    if ($identity['assignment_id'] !== null && !$hasAssignmentColumn) {
        throw new RuntimeException('Assignment-linked submissions are not available until the database migration is applied.');
    }

    if ($identity['assignment_id'] !== null) {
        $sql = 'SELECT id FROM submissions WHERE student_id = ? AND assignment_id = ? ORDER BY id ASC LIMIT 1';
        $parameters = [$identity['student_id'], $identity['assignment_id']];
    } elseif ($includeUnlinked) {
        $sql = $hasAssignmentColumn
            ? 'SELECT id FROM submissions
                WHERE student_id = ? AND assignment_id IS NULL
                  AND LOWER(TRIM(subject)) = LOWER(?)
                ORDER BY id ASC LIMIT 1'
            : 'SELECT id FROM submissions
                WHERE student_id = ? AND LOWER(TRIM(subject)) = LOWER(?)
                ORDER BY id ASC LIMIT 1';
        $parameters = [$identity['student_id'], $identity['subject']];
    } else {
        return null;
    }

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($parameters);
    $submissionId = $stmt->fetchColumn();

    return $submissionId === false ? null : (int) $submissionId;
}

function assignment_submission_exists($conn, $studentId, $assignmentId, $subject): bool
{
    return assignment_find_submission($conn, $studentId, $assignmentId, $subject, false, true) !== null;
}

function assignment_audit_table_exists($conn): bool
{
    $schemaExpression = assignment_schema_expression($conn);
    $stmt = $conn->prepare(
        "SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = {$schemaExpression}
           AND table_name = 'submission_deletion_audit'"
    );
    $stmt->execute();

    return $stmt->fetchColumn() !== false;
}

function assignment_audit_table_column_names($conn): array
{
    $schemaExpression = assignment_schema_expression($conn);
    $stmt = $conn->prepare(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = {$schemaExpression}
           AND table_name = 'submission_deletion_audit'"
    );
    $stmt->execute();

    $columns = [];
    while (($row = $stmt->fetch_assoc()) !== false) {
        $columns[strtolower((string) $row['column_name'])] = true;
    }

    return $columns;
}

function assignment_audit_index_exists($conn): bool
{
    $indexName = 'idx_submission_deletion_audit_student';

    try {
        if (assignment_driver_name($conn) === 'mysql') {
            $schemaExpression = assignment_schema_expression($conn);
            $stmt = $conn->prepare(
                "SELECT index_name
                 FROM information_schema.statistics
                 WHERE table_schema = {$schemaExpression}
                   AND table_name = 'submission_deletion_audit'
                   AND index_name = ?"
            );
            $stmt->execute([$indexName]);
        } else {
            $stmt = $conn->prepare(
                'SELECT indexname
                 FROM pg_indexes
                 WHERE schemaname = current_schema()
                   AND tablename = ?
                   AND indexname = ?'
            );
            $stmt->execute(['submission_deletion_audit', $indexName]);
        }

        return $stmt->fetchColumn() !== false;
    } catch (Throwable $exception) {
        error_log('EduPortal submission audit index check failed: ' . $exception->getMessage());
        return false;
    }
}

function assignment_ensure_audit_schema($conn): bool
{
    try {
        if (assignment_audit_table_exists($conn)) {
            $columns = assignment_audit_table_column_names($conn);
            if (!isset($columns['existing_submission_id'])) {
                $existingColumnType = assignment_driver_name($conn) === 'mysql' ? 'INT(11)' : 'INTEGER';
                try {
                    $conn->exec("ALTER TABLE submission_deletion_audit ADD COLUMN existing_submission_id {$existingColumnType} DEFAULT NULL");
                } catch (Throwable $exception) {
                    $columns = assignment_audit_table_column_names($conn);
                    if (!isset($columns['existing_submission_id'])) {
                        throw $exception;
                    }
                }
                $columns['existing_submission_id'] = true;
            }
            if (!isset($columns['file_removed'])) {
                try {
                    $conn->exec('ALTER TABLE submission_deletion_audit ADD COLUMN file_removed SMALLINT NOT NULL DEFAULT 0');
                } catch (Throwable $exception) {
                    $columns = assignment_audit_table_column_names($conn);
                    if (!isset($columns['file_removed'])) {
                        throw $exception;
                    }
                }
                $columns['file_removed'] = true;
            }
            if (!isset($columns['event'])) {
                try {
                    $conn->exec("ALTER TABLE submission_deletion_audit ADD COLUMN event VARCHAR(100) NOT NULL DEFAULT 'submission_file_deleted'");
                } catch (Throwable $exception) {
                    $columns = assignment_audit_table_column_names($conn);
                    if (!isset($columns['event'])) {
                        throw $exception;
                    }
                }
                $columns['event'] = true;
            }
            $requiredColumns = [
                'id',
                'submission_id',
                'existing_submission_id',
                'student_id',
                'assignment_id',
                'subject',
                'file_path',
                'file_removed',
                'event',
                'reason',
                'actor_id',
                'created_at'
            ];
            foreach ($requiredColumns as $requiredColumn) {
                if (!isset($columns[$requiredColumn])) {
                    error_log('EduPortal submission audit schema is missing column: ' . $requiredColumn);
                    return false;
                }
            }
            return true;
        }

        $isMysql = assignment_schema_expression($conn) === 'DATABASE()';
        $tableSql = $isMysql
            ? "CREATE TABLE IF NOT EXISTS submission_deletion_audit (
                   id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                   submission_id INT(11) DEFAULT NULL,
                   existing_submission_id INT(11) DEFAULT NULL,
                   student_id INT(11) DEFAULT NULL,
                   assignment_id INT(11) DEFAULT NULL,
                   subject VARCHAR(255) DEFAULT NULL,
                   file_path TEXT,
                   file_removed SMALLINT NOT NULL DEFAULT 0,
                   event VARCHAR(100) NOT NULL DEFAULT 'submission_file_deleted',
                   reason VARCHAR(100) NOT NULL,
                   actor_id INT(11) DEFAULT NULL,
                   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                   PRIMARY KEY (id)
               )"
            : "CREATE TABLE IF NOT EXISTS submission_deletion_audit (
                   id BIGSERIAL PRIMARY KEY,
                   submission_id INTEGER,
                   existing_submission_id INTEGER,
                   student_id INTEGER,
                   assignment_id INTEGER,
                   subject VARCHAR(255),
                   file_path TEXT,
                   file_removed SMALLINT NOT NULL DEFAULT 0,
                   event VARCHAR(100) NOT NULL DEFAULT 'submission_file_deleted',
                   reason VARCHAR(100) NOT NULL,
                   actor_id INTEGER,
                   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
               )";
        $conn->exec($tableSql);
        $indexSql = $isMysql
            ? 'CREATE INDEX idx_submission_deletion_audit_student ON submission_deletion_audit (student_id, created_at)'
            : 'CREATE INDEX IF NOT EXISTS idx_submission_deletion_audit_student ON submission_deletion_audit (student_id, created_at)';
        if (!assignment_audit_index_exists($conn)) {
            try {
                $conn->exec($indexSql);
            } catch (Throwable $exception) {
                error_log('EduPortal submission audit index could not be created: ' . $exception->getMessage());
            }
        }

        return true;
    } catch (Throwable $exception) {
        error_log('EduPortal submission audit schema migration failed: ' . $exception->getMessage());
        return false;
    }
}

function assignment_record_deletion_audit($conn, array $details): bool
{
    $context = [
        'event' => (string) ($details['event'] ?? 'submission_file_deleted'),
        'reason' => (string) ($details['reason'] ?? 'duplicate_submission'),
        'submission_id' => isset($details['submission_id']) && $details['submission_id'] !== null ? (int) $details['submission_id'] : null,
        'existing_submission_id' => isset($details['existing_submission_id']) && $details['existing_submission_id'] !== null ? (int) $details['existing_submission_id'] : null,
        'student_id' => isset($details['student_id']) && $details['student_id'] !== null ? (int) $details['student_id'] : null,
        'assignment_id' => isset($details['assignment_id']) && $details['assignment_id'] !== null ? (int) $details['assignment_id'] : null,
        'subject' => assignment_normalize_subject($details['subject'] ?? ''),
        'file_path' => (string) ($details['file_path'] ?? ''),
        'file_removed' => (bool) ($details['file_removed'] ?? false)
    ];
    error_log('EduPortal submission deletion audit: ' . json_encode($context, JSON_UNESCAPED_SLASHES));

    if (!assignment_ensure_audit_schema($conn)) {
        return false;
    }

    try {
        $stmt = $conn->prepare(
            'INSERT INTO submission_deletion_audit
             (submission_id, existing_submission_id, student_id, assignment_id, subject, file_path, file_removed, event, reason, actor_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $context['submission_id'],
            $context['existing_submission_id'],
            $context['student_id'],
            $context['assignment_id'],
            $context['subject'],
            $context['file_path'],
            $context['file_removed'] ? 1 : 0,
            $context['event'],
            $context['reason'],
            isset($details['actor_id']) && $details['actor_id'] !== null ? (int) $details['actor_id'] : null
        ]);
        return true;
    } catch (Throwable $exception) {
        error_log('EduPortal submission audit insert failed: ' . $exception->getMessage());
        return false;
    }
}

function assignment_discard_duplicate_submission($conn, $filePath, array $details): bool
{
    $fileRemoved = assignment_remove_stored_file($filePath);
    $details['event'] = 'duplicate_upload_discarded';
    $details['reason'] = 'duplicate_submission';
    $details['file_removed'] = $fileRemoved;
    assignment_record_deletion_audit($conn, $details);

    return $fileRemoved;
}

function assignment_is_duplicate_database_error(Throwable $exception): bool
{
    $code = (string) $exception->getCode();
    if ($code === '23505' || $code === '1062') {
        return true;
    }

    if (isset($exception->errorInfo[1]) && (int) $exception->errorInfo[1] === 1062) {
        return true;
    }

    $message = strtolower($exception->getMessage());
    return strpos($message, 'unique constraint') !== false
        || strpos($message, 'unique violation') !== false
        || strpos($message, 'duplicate key') !== false
        || strpos($message, 'duplicate entry') !== false;
}

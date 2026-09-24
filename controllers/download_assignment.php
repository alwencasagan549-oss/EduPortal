<?php
/**
 * Secure Downloader for Teacher-Broadcasted Assignments
 * Looks up file from posted_assignments table and serves with RLS.
 */

require_once __DIR__ . '/../config/database.php';

function renderDownloadError(string $title, string $message, string $backUrl, string $backLabel, int $status = 404): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeBackUrl = htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8');
    $safeRetryUrl = htmlspecialchars($_SERVER['REQUEST_URI'] ?? $backUrl, ENT_QUOTES, 'UTF-8');
    $safeBackLabel = htmlspecialchars($backLabel, ENT_QUOTES, 'UTF-8');
    $safeStatus = htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $safeTitle ?> | EduPortal</title>
    <link rel="icon" href="../assets/favicon.ico?v=20260924-ico" type="image/x-icon">
    <link rel="stylesheet" href="../assets/style.min.css?v=20260924">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { color-scheme: dark; }
        .download-error-page {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: clamp(1rem, 4vw, 3rem);
            position: relative;
            isolation: isolate;
        }
        .download-error-page::before,
        .download-error-page::after {
            content: '';
            position: absolute;
            z-index: -1;
            border-radius: 50%;
            pointer-events: none;
        }
        .download-error-page::before {
            width: min(70vw, 620px);
            height: min(70vw, 620px);
            top: -18%;
            right: -12%;
            background: radial-gradient(circle, rgba(78, 115, 223, 0.16), transparent 68%);
        }
        .download-error-page::after {
            width: min(60vw, 520px);
            height: min(60vw, 520px);
            bottom: -22%;
            left: -12%;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.1), transparent 68%);
        }
        .download-error-shell {
            width: min(100%, 680px);
        }
        .download-error-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .download-error-brand {
            display: inline-flex;
            align-items: center;
            gap: 0.7rem;
            color: var(--text-main);
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .download-error-brand > span:last-child span { color: var(--primary-color); }
        .download-error-brand-icon {
            width: 2.35rem;
            height: 2.35rem;
            display: inline-grid;
            place-items: center;
            border-radius: 0.75rem;
            background: var(--primary-gradient);
            color: #fff;
            box-shadow: 0 8px 22px rgba(78, 115, 223, 0.3);
        }
        .download-error-status {
            color: var(--text-muted);
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .download-error-card {
            position: relative;
            overflow: hidden;
            padding: clamp(2rem, 6vw, 4rem);
            border: 1px solid var(--glass-border);
            border-radius: 1.75rem;
            background: linear-gradient(145deg, rgba(28, 31, 44, 0.92), rgba(15, 17, 25, 0.94));
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.35);
            animation: download-error-rise 0.45s ease both;
        }
        .download-error-card::after {
            content: '';
            position: absolute;
            width: 16rem;
            height: 16rem;
            top: -9rem;
            right: -7rem;
            border: 1px solid rgba(78, 115, 223, 0.16);
            border-radius: 50%;
            box-shadow: 0 0 0 2rem rgba(78, 115, 223, 0.025), 0 0 0 4rem rgba(78, 115, 223, 0.02);
            pointer-events: none;
        }
        .download-error-mark {
            position: relative;
            z-index: 1;
            width: 5.5rem;
            height: 5.5rem;
            display: grid;
            place-items: center;
            margin-bottom: 2rem;
            border: 1px solid rgba(239, 68, 68, 0.25);
            border-radius: 1.5rem;
            background: rgba(239, 68, 68, 0.11);
            color: #fb7185;
            box-shadow: 0 0 0 0.65rem rgba(239, 68, 68, 0.045);
        }
        .download-error-mark i { font-size: 2.35rem; }
        .download-error-eyebrow {
            position: relative;
            z-index: 1;
            margin-bottom: 0.75rem;
            color: #fb7185;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .download-error-title {
            position: relative;
            z-index: 1;
            max-width: 34rem;
            margin-bottom: 1rem;
            color: var(--text-main);
            font-size: clamp(2rem, 6vw, 3.35rem);
            line-height: 1.04;
            letter-spacing: -0.045em;
        }
        .download-error-message {
            position: relative;
            z-index: 1;
            max-width: 34rem;
            color: var(--text-muted);
            font-size: 1rem;
            line-height: 1.7;
        }
        .download-error-note {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            max-width: 34rem;
            margin-top: 1.75rem;
            padding: 1rem 1.1rem;
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 0.9rem;
            background: rgba(255, 255, 255, 0.035);
            color: var(--text-muted);
            font-size: 0.88rem;
        }
        .download-error-note i { margin-top: 0.15rem; color: #fb7185; }
        .download-error-actions {
            position: relative;
            z-index: 1;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 2rem;
        }
        .download-error-button {
            min-height: 3rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            padding: 0.8rem 1.15rem;
            border: 1px solid transparent;
            border-radius: 0.85rem;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }
        .download-error-button:hover { transform: translateY(-2px); }
        .download-error-button-primary { background: var(--primary-gradient); color: #fff; box-shadow: 0 10px 24px rgba(78, 115, 223, 0.25); }
        .download-error-button-primary:hover { background: linear-gradient(135deg, #5d80e7, #274fc8); }
        .download-error-button-secondary { border-color: var(--glass-border); background: rgba(255, 255, 255, 0.04); color: var(--text-main); }
        .download-error-button-secondary:hover { border-color: rgba(78, 115, 223, 0.45); background: rgba(78, 115, 223, 0.1); }
        .download-error-footnote {
            position: relative;
            z-index: 1;
            margin-top: 1.75rem;
            color: #64748b;
            font-size: 0.78rem;
        }
        .download-error-brand:focus-visible,
        .download-error-button:focus-visible { outline: 3px solid rgba(78, 115, 223, 0.55); outline-offset: 4px; }
        @keyframes download-error-rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 520px) {
            .download-error-header { align-items: flex-start; flex-direction: column; }
            .download-error-status { padding-left: 3.05rem; }
            .download-error-actions { flex-direction: column; }
            .download-error-button { width: 100%; }
        }
        @media (prefers-reduced-motion: reduce) {
            .download-error-card { animation: none; }
            .download-error-button { transition: none; }
        }
    </style>
</head>
<body>
    <main class="download-error-page">
        <div class="download-error-shell">
            <header class="download-error-header">
                <a class="download-error-brand" href="<?= $safeBackUrl ?>">
                    <span class="download-error-brand-icon"><i class="fas fa-graduation-cap" aria-hidden="true"></i></span>
                    <span>Edu<span>Portal</span></span>
                </a>
                <span class="download-error-status">File status / <?= $safeStatus ?></span>
            </header>
            <section class="download-error-card" aria-labelledby="download-error-title">
                <div class="download-error-mark" aria-hidden="true"><i class="fas fa-file-circle-xmark"></i></div>
                <p class="download-error-eyebrow">Assignment file</p>
                <h1 class="download-error-title" id="download-error-title"><?= $safeTitle ?></h1>
                <p class="download-error-message"><?= $safeMessage ?></p>
                <div class="download-error-note" role="note">
                    <i class="fas fa-circle-info" aria-hidden="true"></i>
                    <span>Ask the assignment owner to publish a fresh copy if you still need this material.</span>
                </div>
                <div class="download-error-actions">
                    <a class="download-error-button download-error-button-primary" href="<?= $safeBackUrl ?>">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i>
                        <?= $safeBackLabel ?>
                    </a>
                    <a class="download-error-button download-error-button-secondary" href="<?= $safeRetryUrl ?>">
                        <i class="fas fa-rotate-right" aria-hidden="true"></i>
                        Try again
                    </a>
                </div>
                <p class="download-error-footnote">EduPortal LMS · Secure assignment access</p>
            </section>
        </div>
    </main>
</body>
</html>
<?php
}

function assignmentDownloadFilename($filePath): string
{
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename(str_replace('\\', '/', (string) $filePath)));
    return is_string($filename) && $filename !== '' ? $filename : 'assignment-file';
}

function assignmentDownloadMime($mimeType): string
{
    return is_string($mimeType) && preg_match('/^[A-Za-z0-9.+-]+\/[A-Za-z0-9.+-]+$/', $mimeType)
        ? $mimeType
        : 'application/octet-stream';
}

if (!isLoggedIn()) {
    header('Location: ../session_expired.php');
    exit();
}

$user_role = $_SESSION['user_role'] ?? '';
if ($user_role === 'teacher') {
    $backUrl = '../teacher/assignments.php';
    $backLabel = 'Back to posted assignments';
} elseif ($user_role === 'student') {
    $backUrl = '../student/assignments.php';
    $backLabel = 'Back to assignments';
} else {
    renderDownloadError('Access denied', 'Your account cannot download assignment files.', '../session_expired.php', 'Sign in again', 403);
    exit();
}

$idValue = $_GET['id'] ?? null;
$id = is_string($idValue) || is_int($idValue)
    ? filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]])
    : false;
if ($id === false) {
    renderDownloadError('Invalid download request', 'This link does not include a valid assignment ID.', $backUrl, $backLabel, 400);
    exit();
}
$id = (int) $id;
$conn = getDBConnection();

// Auto-create file_content columns if they don't exist (self-healing, PostgreSQL + MySQL safe)
$storageColumns = [];
try {
    $check = $conn->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'posted_assignments' AND column_name IN ('file_content', 'file_type')");
    $check->execute();
    $storageColumns = [];
    $columnResult = $check->get_result();
    while ($column = $columnResult->fetch_assoc()) {
        $storageColumns[(string) $column['column_name']] = true;
    }
    if (!isset($storageColumns['file_content'])) {
        if ($conn->getDriverName() === 'mysql') {
            $conn->exec('ALTER TABLE posted_assignments ADD COLUMN file_content LONGTEXT');
        } else {
            $conn->exec('ALTER TABLE posted_assignments ADD COLUMN file_content TEXT DEFAULT NULL');
        }
        $storageColumns['file_content'] = true;
    }
    if (!isset($storageColumns['file_type'])) {
        $conn->exec('ALTER TABLE posted_assignments ADD COLUMN file_type VARCHAR(100) DEFAULT \'application/octet-stream\'');
        $storageColumns['file_type'] = true;
    }
} catch (Throwable $e) {
    error_log('EduPortal schema migration error in download_assignment.php: ' . $e->getMessage());
}

if (!isset($storageColumns['file_content'], $storageColumns['file_type'])) {
    renderDownloadError('Assignment storage is not ready', 'The assignment file service is temporarily unavailable.', $backUrl, $backLabel, 500);
    exit();
}

if ($user_role === 'teacher') {
    $stmt = $conn->prepare('SELECT file_path, file_content, file_type FROM posted_assignments WHERE id = ? AND teacher_id = ?');
    $stmt->execute([$id, (int) ($_SESSION['user_id'] ?? 0)]);
} else {
    $stmt = $conn->prepare('SELECT file_path, file_content, file_type FROM posted_assignments WHERE id = ? AND grade_level = ? AND section = ? AND strand = ?');
    $stmt->execute([
        $id,
        $_SESSION['user_grade'] ?? '',
        $_SESSION['user_section'] ?? '',
        $_SESSION['user_strand'] ?? 'Academic'
    ]);
}
$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment) {
    renderDownloadError('Assignment not found', 'This assignment may have been removed, or the link may be out of date.', $backUrl, $backLabel, 404);
    exit();
}
$file_path = $assignment['file_path'];

// Serve from database if content is stored there (Render compatibility)
if (!empty($assignment['file_content'])) {
    $file_data = base64_decode((string) $assignment['file_content'], true);
    if ($file_data === false) {
        renderDownloadError('Assignment file unavailable', 'The stored copy of this file is invalid. Ask your teacher to publish a fresh copy.', $backUrl, $backLabel, 404);
        exit();
    }
    $filename = assignmentDownloadFilename($file_path);
    $filetype = assignmentDownloadMime($assignment['file_type'] ?? '');
    $filesize = strlen($file_data);

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $filetype);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . $filesize);
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');

    if (ob_get_level()) {
        ob_end_clean();
    }

    echo $file_data;
    exit();
}

// Fallback: serve from filesystem
$baseDirectories = [];
foreach ([__DIR__ . '/../uploads', __DIR__ . '/uploads'] as $basePath) {
    $resolvedBase = realpath($basePath);
    if ($resolvedBase !== false) {
        $baseDirectories[$resolvedBase] = $resolvedBase;
    }
}

if ($baseDirectories === []) {
    renderDownloadError('Downloads are temporarily unavailable', 'The file storage area is not ready. Please try again in a moment.', $backUrl, $backLabel, 500);
    exit();
}

$relative = ltrim(str_replace('\\', '/', (string) $file_path), '/');
$relative = preg_replace('#^(?:controllers/)?uploads/#i', '', $relative) ?? $relative;
$candidate = null;
$resolved = false;
$resolvedBase = null;

foreach ($baseDirectories as $baseDirectory) {
    $candidatePath = $baseDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!file_exists($candidatePath)) {
        continue;
    }

    $candidate = $candidatePath;
    $resolved = realpath($candidatePath);
    $resolvedBase = $baseDirectory;
    break;
}

if ($resolved === false || $resolvedBase === null) {
    renderDownloadError('Assignment file unavailable', 'This copy is no longer stored in the portal. Ask your teacher to publish a fresh copy.', $backUrl, $backLabel, 404);
    exit();
}

$baseNorm = rtrim(str_replace('\\', '/', $resolvedBase), '/') . '/';
$resolvedNorm = str_replace('\\', '/', $resolved);

if (!is_file($resolved) || strpos($resolvedNorm, $baseNorm) !== 0) {
    renderDownloadError('Access denied', 'You do not have permission to download this assignment.', $backUrl, $backLabel, 403);
    exit();
}

$filename = assignmentDownloadFilename($resolved);
$filetype = assignmentDownloadMime(function_exists('mime_content_type') ? mime_content_type($resolved) : false);
$filesize = filesize($resolved);
if ($filesize === false) {
    renderDownloadError('Assignment file unavailable', 'The stored copy of this file could not be read.', $backUrl, $backLabel, 404);
    exit();
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $filetype);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $filesize);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

if (ob_get_level()) {
    ob_end_clean();
}

readfile($resolved);
exit();

<?php
require_once '../config/database.php';
require_once '../libs/AuthService.php';
require_once '../libs/teacher_account.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';

function teacher_signup_problem(array $input): ?string
{
    if (!$input['csrf_valid']) {
        return 'Invalid security token.';
    }
    if ($input['password'] !== $input['confirm_password']) {
        return 'Passwords do not match';
    }
    if ($input['subject'] === '') {
        return 'Please provide a subject';
    }
    if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        return 'Please provide a valid professional email address';
    }
    if (($passwordProblem = auth_password_problem((string) $input['password'])) !== null) {
        return $passwordProblem;
    }
    if (assignment_subject_length($input['name']) > 100) {
        return 'Name must be 100 characters or fewer';
    }
    if (assignment_subject_length($input['email']) > 100) {
        return 'Email must be 100 characters or fewer';
    }
    if (assignment_subject_length($input['subject']) > 100) {
        return 'Subject must be 100 characters or fewer';
    }

    return null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = normalize_teacher_subject($_POST['subject'] ?? $_POST['teacher_type'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $error = teacher_signup_problem([
        'csrf_valid' => validate_csrf($_POST['csrf_token'] ?? ''),
        'name' => $name,
        'email' => $email,
        'subject' => $subject,
        'password' => $password,
        'confirm_password' => $confirm_password,
    ]);

    if ($error === null) {
        $hashed_password = auth_password_hash($password);

        try {
            $conn = getDBConnection();

            // Uniqueness is per (email, subject), not per either one alone.
            // One teacher may hold several rows sharing an email, one per
            // subject, which is what makes the same email able to sign in
            // against different subjects at all. Two teachers may also share a
            // subject. The previous check joined them with OR, so it refused
            // both of those legitimate registrations.
            //
            // Both sides are trimmed and lowercased on the column as well as
            // the parameter: "Math " and "math" are the same subject, and
            // comparing raw values let case variants through.
            $check = $conn->prepare(
                'SELECT id FROM teachers
                 WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
                   AND LOWER(TRIM(subject)) = LOWER(TRIM(?))'
            );
            $check->execute([$email, $subject]);

            if ($check->get_result()->num_rows() > 0) {
                $error = 'A teacher with this email already teaches this subject. Sign in to that account, or choose a different subject.';
            } elseif (teacher_account_column_exists($conn, 'status')) {
                $stmt = $conn->prepare(
                    "INSERT INTO teachers (name, email, subject, password, status) VALUES (?, ?, ?, ?, 'pending')"
                );
                $stmt->execute([$name, $email, $subject, $hashed_password]);
                $success = teacher_account_status_message('pending')
                    . ' Your requested subject has been reserved.';
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO teachers (name, email, subject, password) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$name, $email, $subject, $hashed_password]);
                $success = 'Registration successful! You can now login.';
            }

            if ($error === null && $success === '' && $stmt->rowCount() <= 0) {
                $error = 'Registration failed';
            }
        } catch (PDOException $exception) {
            $sqlState = (string) $exception->getCode();
            $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;
            if ($sqlState === '23505' || $driverCode === 1062) {
                $error = 'A teacher with this email already teaches this subject. Sign in to that account, or choose a different subject.';
            } else {
                error_log('Teacher registration failed: ' . $exception->getMessage());
                $error = 'Registration could not be completed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Registration | EduPortal LMS</title>
    <link rel="icon" href="../assets/favicon.ico?v=20260924-ico" type="image/x-icon">
    <link rel="manifest" href="../manifest.webmanifest">
    <meta name="theme-color" content="#0a0b10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="../assets/pwa-icon-192.svg">
    <link rel="stylesheet" href="../assets/style.min.css?v=20260924">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
</head>
<body class="auth-page">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <main class="auth-card" id="main-content" style="max-width: 550px;">
        <div class="auth-logo">
            <div class="sidebar-logo" style="margin: 0 auto 1.5rem; width: 60px; height: 60px;">
                <i class="fas fa-user-plus" style="font-size: 1.5rem;"></i>
            </div>
            <h1 class="auth-title">Teacher Signup</h1>
            <p class="auth-subtitle">Submit a request for teaching access</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-circle-xmark"></i> <div><?php echo htmlspecialchars($error); ?></div></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-circle-check"></i> <div><?php echo htmlspecialchars($success); ?></div></div>
        <?php endif; ?>

        <form method="POST" data-loader="true">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <label for="name" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Full Name</label>
                    <input type="text" id="name" name="name" autocomplete="name" required class="premium-input" placeholder="Mr. John Doe">
                </div>
                <div>
                    <label for="email" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Professional Email</label>
                    <input type="email" id="email" name="email" autocomplete="email" required class="premium-input" placeholder="john@school.com">
                </div>
            </div>
            
            <div style="margin-bottom: 1.5rem;">
                <label for="subject" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Specialization / Subject</label>
                <input type="text" id="subject" name="subject" autocomplete="organization-title" required class="premium-input" placeholder="Mathematics, Science, etc.">
                <small style="display: block; margin-top: 0.45rem; color: var(--text-muted); font-size: 0.78rem;">Please enter only one primary subject you will be teaching. Teaching access is granted after administrator approval.</small>
            </div>
            
            <div class="responsive-grid-stack" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
                <div>
                    <label for="password" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Password</label>
                    <input type="password" id="password" name="password" autocomplete="new-password" required class="premium-input" placeholder="••••••••">
                </div>
                <div>
                    <label for="confirm_password" style="display: block; margin-bottom: 0.5rem; color: var(--text-muted); font-size: 0.85rem;">Confirm</label>
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required class="premium-input" placeholder="••••••••">
                </div>
            </div>
            
            <button type="submit" class="premium-btn premium-btn-primary" style="width: 100%; justify-content: center; padding: 1rem;">
                <i class="fas fa-user-check"></i> Create Teacher Account
            </button>
        </form>

        <div style="margin-top: 2rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; text-align: center;">
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1rem;">Already have teacher credentials?</p>
            <a href="login.php" class="premium-btn premium-btn-outline" style="width: 100%; justify-content: center;">
                <i class="fas fa-right-to-bracket"></i> Instructor Login
            </a>
            <div style="margin-top: 2.5rem; border-top: 1px solid var(--glass-border); padding-top: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.8rem;">
                <p>&copy; 2026 EduPortal. Web Developer: <strong id="_sys_v_auth"><a href="https://casagan.vercel.app/" target="_blank" style="color: inherit; text-decoration: none; transition: color 0.2s;" onmouseover="this.style.color='#4e73df'" onmouseout="this.style.color='inherit'">Alwin T. Casagan</a></strong></p>
                <div style="margin-top: 1rem;">
                    <a href="../index.php" style="color: var(--text-muted); text-decoration: none; font-size: 0.85rem;" onmouseover="this.style.color='var(--text-main)'" onmouseout="this.style.color='var(--text-muted)'">
                        <i class="fas fa-arrow-left"></i> Home Page
                    </a>
                </div>
            </div>
        </div>
    </main>
    <script src="../assets/js/trusted_types.js"></script>
    <script src="../assets/js/system_loader.js?v=20260924-loader4"></script>
    <script src="../assets/js/responsive_ui.js"></script>
    <script src="../assets/js/pwa.js"></script>
</body>
</html>

<?php
$db_path = file_exists(__DIR__ . '/../config/database.php') ? __DIR__ . '/../config/database.php' : __DIR__ . '/config/database.php';
require_once $db_path;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Expired | EduPortal LMS</title>
    <link rel="icon" href="assets/pwa-icon-192.svg" type="image/svg+xml">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#0a0b10">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="assets/pwa-icon-192.svg">
    <link rel="stylesheet" href="assets/style.min.css?v=20260924">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <style>
        .session-expired-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-body);
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }
        .session-expired-wrapper::before {
            content: '';
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.08) 0%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
        }
        .expired-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 3.5rem;
            max-width: 500px;
            width: 100%;
            text-align: center;
            position: relative;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            animation: animate-scale-up 0.5s ease-out;
        }
        .expired-icon {
            width: 90px;
            height: 90px;
            background: rgba(239, 68, 68, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            border: 2px solid rgba(239, 68, 68, 0.2);
        }
        .expired-icon i {
            font-size: 2.5rem;
            color: #ef4444;
        }
        .expired-card h1 {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 0.75rem;
            letter-spacing: -0.5px;
        }
        .expired-card .expired-subtitle {
            color: var(--text-muted);
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 2.5rem;
        }
        .expired-card .expired-reason {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1rem 1.25rem;
            margin-bottom: 2.5rem;
            font-size: 0.9rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-align: left;
        }
        .expired-card .expired-reason i {
            color: #ef4444;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .expired-buttons {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .expired-buttons .premium-btn {
            width: 100%;
            justify-content: center;
            padding: 0.9rem 1.5rem;
            font-size: 0.95rem;
        }
        .expired-footer {
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--glass-border);
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .expired-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }
        .expired-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <main class="session-expired-wrapper" id="main-content">
        <div class="expired-card">
            <div class="expired-icon">
                <i class="fas fa-clock"></i>
            </div>
            <h1>Session Expired</h1>
            <p class="expired-subtitle">Your session has timed out for security. Please sign in again to continue.</p>

            <div class="expired-reason">
                <i class="fas fa-info-circle"></i>
                <span>Sessions expire after 30 minutes of inactivity to protect your account from unauthorized access.</span>
            </div>

            <div class="expired-buttons">
                <a href="student/login.php" class="premium-btn premium-btn-primary">
                    <i class="fas fa-user-graduate"></i> Student Login
                </a>
                <a href="teacher/login.php" class="premium-btn premium-btn-primary" style="background: var(--accent-gradient);">
                    <i class="fas fa-chalkboard-user"></i> Teacher Login
                </a>
            </div>

            <div class="expired-footer">
                <p>&copy; <?php echo date('Y'); ?> EduPortal LMS. <a href="index.php">Return to portal</a></p>
            </div>
        </div>
    </main>
    <script src="assets/js/trusted_types.js"></script>
    <script src="assets/js/system_loader.js?v=20260924-loader3"></script>
    <script src="assets/js/pwa.js"></script>
</body>
</html>

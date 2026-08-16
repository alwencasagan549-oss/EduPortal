<?php
// setup.php - Database setup wizard with error handling
if (session_status() === PHP_SESSION_NONE) session_start();

$step = $_GET['step'] ?? 1;
$message = '';
$error = '';
$debug_info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_once 'config/database.php';
        $conn = getDBConnection();

        $post_step = $_POST['step'] ?? 1;

        if ($post_step == 1) {
            $tables = [
                "CREATE TABLE IF NOT EXISTS admin (
                    id SERIAL PRIMARY KEY,
                    username VARCHAR(50) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS teachers (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(100) NOT NULL,
                    subject VARCHAR(100) NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS students (
                    id SERIAL PRIMARY KEY,
                    lrn VARCHAR(20) UNIQUE NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(100),
                    password VARCHAR(255) NOT NULL,
                    grade_level VARCHAR(50),
                    section VARCHAR(50),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS submissions (
                    id SERIAL PRIMARY KEY,
                    student_id INTEGER REFERENCES students(id) ON DELETE SET NULL,
                    teacher_id INTEGER REFERENCES teachers(id) ON DELETE SET NULL,
                    student_name VARCHAR(100) DEFAULT NULL,
                    subject VARCHAR(100) NOT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    marks VARCHAR(10) DEFAULT NULL,
                    remarks TEXT DEFAULT NULL,
                    submission_date DATE NOT NULL,
                    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS posted_assignments (
                    id SERIAL PRIMARY KEY,
                    teacher_id INTEGER NOT NULL,
                    teacher_name VARCHAR(255) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    file_path VARCHAR(255) NOT NULL,
                    grade_level VARCHAR(50) NOT NULL,
                    strand VARCHAR(50) NOT NULL,
                    section VARCHAR(50) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )",
                "CREATE TABLE IF NOT EXISTS jobs (
                    id SERIAL PRIMARY KEY,
                    type VARCHAR(50) NOT NULL,
                    payload TEXT NOT NULL,
                    status VARCHAR(20) DEFAULT 'pending',
                    error_message TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )"
            ];

            foreach ($tables as $sql) {
                $conn->exec($sql);
            }

            $conn->exec("ALTER TABLE posted_assignments ADD COLUMN IF NOT EXISTS strand VARCHAR(50) NOT NULL DEFAULT 'Academic'");

            // Verify tables exist
            $verify = $conn->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'submissions'");
            $result = $verify->fetchAll();
            if (count($result) > 0) {
                $message = "Tables created successfully! Now seeding default accounts.";
                $step = 2;
            } else {
                $error = "Tables may not have been created properly. Please try again.";
            }
        }

        if ($post_step == 2 && empty($error)) {
            $admin_pw = password_hash('admin123', PASSWORD_BCRYPT);
            $teacher_pw = password_hash('teacher123', PASSWORD_BCRYPT);
            $student_pw = password_hash('student123', PASSWORD_BCRYPT);

            $stmt = $conn->prepare("INSERT INTO admin (username, password) VALUES ('admin', :p) ON CONFLICT (username) DO UPDATE SET password = :p");
            $stmt->execute(['p' => $admin_pw]);

            $stmt = $conn->prepare("INSERT INTO teachers (name, email, subject, password) VALUES ('John Smith', 'john@example.com', 'Mathematics', :p), ('Sarah Johnson', 'sarah@example.com', 'Physics', :p) ON CONFLICT (email) DO UPDATE SET password = EXCLUDED.password");
            $stmt->execute(['p' => $teacher_pw]);

            $stmt = $conn->prepare("INSERT INTO students (lrn, name, email, password, grade_level, section) VALUES ('123456789012', 'Alex Johnson', 'alex@example.com', :p, 'Grade 11', 'STEM-A') ON CONFLICT (lrn) DO UPDATE SET password = EXCLUDED.password");
            $stmt->execute(['p' => $student_pw]);

            $message = "Setup complete! All tables created and accounts seeded.";
            $step = 3;
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
        $debug_info = "Step: " . ($post_step ?? 'unknown');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>EduPortal Setup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; max-width: 700px; }
        .success { color: green; }
        .error { color: red; }
        .debug { color: #666; font-size: 0.9em; margin-top: 10px; }
        .step { padding: 20px; border-left: 4px solid #4e73df; margin: 20px 0; }
        button { padding: 10px 20px; background: #4e73df; color: white; border: none; cursor: pointer; }
        a.btn { background:#4e73df;color:white;padding:10px 20px;text-decoration:none;border-radius:5px; }
    </style>
</head>
<body>
    <h1>EduPortal LMS Setup</h1>
    <hr>

    <?php if ($message): ?>
        <div class="step"><p class="success"><?php echo htmlspecialchars($message); ?></p></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="step"><p class="error"><?php echo htmlspecialchars($error); ?></p><?php if ($debug_info): ?><p class="debug"><?php echo htmlspecialchars($debug_info); ?></p><?php endif; ?></div>
    <?php endif; ?>

    <?php if ($step == 1): ?>
        <div class="step">
            <h3>Step 1: Create Database Tables</h3>
            <p>This will create all required tables in your PostgreSQL database.</p>
            <p><strong>Credentials after setup:</strong></p>
            <ul>
                <li>Admin: <code>admin</code> / <code>admin123</code></li>
                <li>Teacher: <code>john@example.com</code> / <code>teacher123</code></li>
                <li>Student LRN: <code>123456789012</code> / <code>student123</code></li>
            </ul>
            <form method="POST" data-loader="true"><input type="hidden" name="step" value="1"><button type="submit">Create Tables</button></form>
        </div>
    <?php elseif ($step == 2): ?>
        <div class="step">
            <h3>Step 2: Seed Default Data</h3>
            <p>Creating admin, teacher, and student accounts...</p>
            <form method="POST" data-loader="true"><input type="hidden" name="step" value="2"><button type="submit">Seed Data</button></form>
        </div>
    <?php elseif ($step == 3): ?>
        <div class="step">
            <h3 class="success">Setup Complete!</h3>
            <p><strong>IMPORTANT:</strong> Delete this file (setup.php) after use for security.</p>
            <p><a href="index.php" class="btn">Go to Portal</a></p>
        </div>
    <?php endif; ?>
</body>
</html>

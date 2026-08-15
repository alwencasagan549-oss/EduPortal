<?php
// setup.php - Database setup wizard
if (session_status() === PHP_SESSION_NONE) session_start();

$step = $_GET['step'] ?? 1;
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';
    $conn = getDBConnection();

    if ($step == 2) {
        // Create tables
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
            if (!$conn->query($sql)) {
                $error = "Table error: " . $conn->getPDO()->errorInfo()[2];
                break;
            }
        }

        if (empty($error)) {
            $message = "All tables created successfully!";
            $step = 3;
        }
    }

    if ($step == 3) {
        // Seed data
        $admin_pw = password_hash('admin123', PASSWORD_BCRYPT);
        $teacher_pw = password_hash('teacher123', PASSWORD_BCRYPT);
        $student_pw = password_hash('student123', PASSWORD_BCRYPT);

        $queries = [
            "INSERT INTO admin (username, password) VALUES ('admin', :p) ON CONFLICT (username) DO UPDATE SET password = :p",
            "INSERT INTO teachers (name, email, subject, password) VALUES ('John Smith', 'john@example.com', 'Mathematics', :p), ('Sarah Johnson', 'sarah@example.com', 'Physics', :p) ON CONFLICT (email) DO UPDATE SET password = EXCLUDED.password",
            "INSERT INTO students (lrn, name, email, password, grade_level, section) VALUES ('123456789012', 'Alex Johnson', 'alex@example.com', :p, 'Grade 11', 'STEM-A') ON CONFLICT (lrn) DO UPDATE SET password = EXCLUDED.password"
        ];

        foreach ($queries as $sql) {
            $stmt = $conn->prepare($sql);
            $stmt->execute(['p' => $admin_pw]);
        }

        $message = "Database seeded with default accounts!";
        $step = 4;
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
        .step { padding: 20px; border-left: 4px solid #4e73df; margin: 20px 0; }
        button { padding: 10px 20px; background: #4e73df; color: white; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <h1>EduPortal LMS Setup</h1>
    <hr>

    <?php if ($message): ?>
        <div class="step"><p class="success"><?php echo $message; ?></p></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="step"><p class="error"><?php echo $error; ?></p></div>
    <?php endif; ?>

    <?php if ($step == 1): ?>
        <div class="step">
            <h3>Step 1: Welcome</h3>
            <p>This will create the database tables and seed default accounts.</p>
            <p><strong>Credentials after setup:</strong></p>
            <ul>
                <li>Admin: <code>admin</code> / <code>admin123</code></li>
                <li>Teacher: <code>john@example.com</code> / <code>teacher123</code></li>
                <li>Student LRN: <code>123456789012</code> / <code>student123</code></li>
            </ul>
            <form method="POST"><button type="submit" name="start" value="1">Start Setup</button></form>
        </div>
    <?php elseif ($step == 2): ?>
        <div class="step">
            <h3>Step 2: Creating Tables...</h3>
            <p>Please wait...</p>
        </div>
    <?php elseif ($step == 3): ?>
        <div class="step">
            <h3>Step 3: Seeding Data...</h3>
            <p>Please wait...</p>
        </div>
    <?php elseif ($step == 4): ?>
        <div class="step">
            <h3 class="success">Setup Complete!</h3>
            <p><strong>IMPORTANT:</strong> Delete this file (setup.php) for security.</p>
            <p><a href="index.php" style="background:#4e73df;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;">Go to Portal</a></p>
        </div>
    <?php endif; ?>
</body>
</html>

<?php
// check_session.php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Check Session</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .session-info { background: #f5f5f5; padding: 15px; margin: 15px 0; }
        .btn { padding: 10px 15px; margin: 5px; text-decoration: none; border-radius: 4px; }
        .logout { background: #dc3545; color: white; }
        .login { background: #28a745; color: white; }
    </style>
</head>
<body>
    <h2>Current Session Status</h2>
    
    <div class="session-info">
        <h3>Session Data:</h3>
        <pre><?php print_r($_SESSION); ?></pre>
        
        <h3>Session Status:</h3>
        <?php if (empty($_SESSION)): ?>
            <p style="color: red;">No active session</p>
        <?php else: ?>
            <p style="color: green;">Active session exists</p>
            <?php if (isset($_SESSION['user_id'])): ?>
                <p><strong>Logged in as:</strong> <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Unknown'); ?></p>
                <p><strong>Role:</strong> <?php echo htmlspecialchars($_SESSION['user_role'] ?? 'Unknown'); ?></p>
                <p><strong>LRN:</strong> <?php echo htmlspecialchars($_SESSION['user_lrn'] ?? 'N/A'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <div>
        <a href="logout.php" class="btn logout">Logout</a>
        <a href="student_login.php" class="btn login">Student Login</a>
        <a href="index.php" class="btn">Home</a>
    </div>
    
    <h3>Test Accounts:</h3>
    <ul>
        <li>LRN: 000000000000 | Password: awa</li>
        <li>LRN: 123456789012 | Password: (ask admin)</li>
        <li>LRN: 555555555555 | Password: (ask admin)</li>
    </ul>
</body>
</html>
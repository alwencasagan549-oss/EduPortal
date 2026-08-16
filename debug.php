<?php
// debug.php - Database Diagnostics for PostgreSQL
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) session_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>EduPortal DB Diagnostics</title>
    <style>
        body { font-family: monospace; background: #1a1a2e; color: #eee; padding: 2rem; max-width: 900px; margin: 0 auto; }
        .section { background: #16213e; border-radius: 8px; padding: 1.5rem; margin-bottom: 1rem; }
        .section h2 { color: #4e73df; margin-top: 0; font-size: 1.1rem; }
        .ok { color: #2ecc71; }
        .err { color: #e74c3c; }
        .warn { color: #f39c12; }
        .info { color: #3498db; }
        pre { background: #0a0b10; padding: 1rem; border-radius: 6px; overflow-x: auto; font-size: 0.85rem; white-space: pre-wrap; }
        .kv { display: flex; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .kv:last-child { border: none; }
        .label { color: #94a3b8; }
        .value { color: #f1f5f9; font-weight: bold; }
    </style>
</head>
<body>
    <h1>&#x1F6E0;&#xFE0F; EduPortal Database Diagnostics</h1>

    <div class="section">
        <h2>PHP Environment</h2>
        <div class="kv"><span class="label">PHP Version</span><span class="value"><?php echo PHP_VERSION; ?></span></div>
        <div class="kv"><span class="label">PDO Available</span><span class="value <?php echo class_exists('PDO') ? 'ok' : 'err'; ?>"><?php echo class_exists('PDO') ? 'YES' : 'NO'; ?></span></div>
        <div class="kv"><span class="label">PDO PostgreSQL (pdo_pgsql)</span><span class="value <?php echo in_array('pgsql', PDO::getAvailableDrivers()) ? 'ok' : 'err'; ?>"><?php echo in_array('pgsql', PDO::getAvailableDrivers()) ? 'INSTALLED' : 'MISSING'; ?></span></div>
        <div class="kv"><span class="label">Server Software</span><span class="value"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'; ?></span></div>
        <div class="kv"><span class="label">Server Name</span><span class="value"><?php echo gethostname(); ?></span></div>
    </div>

    <div class="section">
        <h2>Environment Variables (Database)</h2>
        <?php
        $host = getenv('DB_HOST') ?: 'NOT SET (default: localhost)';
        $user = getenv('DB_USER') ?: 'NOT SET (default: root)';
        $pass = getenv('DB_PASS') ?: 'NOT SET (default: empty)';
        $name = getenv('DB_NAME') ?: 'NOT SET (default: edu_portal)';
        $port = getenv('DB_PORT') ?: '5432 (default)';
        ?>
        <div class="kv"><span class="label">DB_HOST</span><span class="value"><?php echo htmlspecialchars($host); ?></span></div>
        <div class="kv"><span class="label">DB_USER</span><span class="value"><?php echo htmlspecialchars($user); ?></span></div>
        <div class="kv"><span class="label">DB_PASS</span><span class="value"><?php echo htmlspecialchars($pass ? str_repeat('*', strlen($pass)) : '(empty)'); ?></span></div>
        <div class="kv"><span class="label">DB_NAME</span><span class="value"><?php echo htmlspecialchars($name); ?></span></div>
        <div class="kv"><span class="label">DB_PORT</span><span class="value"><?php echo htmlspecialchars($port); ?></span></div>
    </div>

    <div class="section">
        <h2>Connection Test</h2>
        <?php
        $host = getenv('DB_HOST') ?: 'localhost';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $name = getenv('DB_NAME') ?: 'edu_portal';
        $port = getenv('DB_PORT') ?: '5432';

        echo "<div class='kv'><span class='label'>Attempting connection to</span><span class='value'>$host:$port/$name as $user</span></div>";

        $start = microtime(true);
        try {
            $dsn = "pgsql:host=$host;port=$port;dbname=$name";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $elapsed = round((microtime(true) - $start) * 1000);
            echo "<p class='ok'>&#x2705; Connected successfully in {$elapsed}ms!</p>";

            // Test tables
            $tables = ['admin', 'teachers', 'students', 'submissions', 'posted_assignments', 'jobs'];
            echo "<h3>Tables:</h3><pre>\n";
            foreach ($tables as $table) {
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
                    $count = $stmt->fetchColumn();
                    echo "  {$table}: {$count} rows &#x2705;\n";
                } catch (Exception $e) {
                    echo "  {$table}: MISSING &#x274C;\n";
                }
            }
            echo "</pre>";

            // Check for strand column
            echo "<h3>Strand Column Check:</h3><pre>\n";
            foreach (['students', 'posted_assignments'] as $table) {
                try {
                    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = 'strand'");
                    $stmt->execute([$table]);
                    if ($stmt->rowCount() > 0) {
                        echo "  {$table}.strand: EXISTS &#x2705;\n";
                    } else {
                        echo "  {$table}.strand: MISSING &#x274C; (run migrations/add_strand_column.php)\n";
                    }
                } catch (Exception $e) {
                    echo "  {$table}: ERROR - " . $e->getMessage() . "\n";
                }
            }
            echo "</pre>";

            // Check for strand values
            try {
                $stmt = $pdo->query("SELECT DISTINCT strand FROM students WHERE strand IS NOT NULL");
                $strands = $stmt->fetchAll(PDO::FETCH_COLUMN);
                echo "<p class='info'>Strand values in students table: " . (empty($strands) ? 'none yet' : implode(', ', $strands)) . "</p>";
            } catch (Exception $e) {
                echo "<p class='err'>Error checking strands: " . $e->getMessage() . "</p>";
            }

        } catch (Exception $e) {
            $elapsed = round((microtime(true) - $start) * 1000);
            echo "<p class='err'>&#x274C; Connection failed after {$elapsed}ms</p>";
            echo "<pre>" . htmlspecialchars($e->getMessage()) . "\n\n";

            // Additional diagnostics
            echo "=== DIAGNOSTICS ===\n";
            echo "Host: $host\n";
            echo "Port: $port\n";
            echo "User: $user\n";

            // Try to resolve hostname
            echo "\nHostname resolution:\n";
            $ips = gethostbynamel($host);
            if ($ips) {
                echo "  Resolved to: " . implode(', ', $ips) . "\n";
            } else {
                echo "  Could not resolve hostname\n";
            }

            // Check if port is reachable
            echo "\nPort check (fsockopen):\n";
            $fp = @fsockopen($host, (int)$port, $errno, $errstr, 3);
            if ($fp) {
                echo "  Port $port is OPEN\n";
                fclose($fp);
            } else {
                echo "  Port $port is CLOSED or BLOCKED ($errstr)\n";
            }

            echo "</pre>";
        }
        ?>
    </div>

    <div class="section">
        <h2>Troubleshooting</h2>
        <pre>
If connection failed:

1. DATABASE CONTAINER NOT RUNNING
   - Go to Coolify dashboard
   - Check if the database service (eduportal-mysql) is running
   - If stopped, start it

2. WRONG PORT
   - PostgreSQL default: 5432
   - MySQL default: 3306
   - If your DB is MySQL, the app needs MySQL driver, not PostgreSQL

3. CREDENTIALS MISMATCH
   - Verify DB_USER, DB_PASS, DB_NAME in Coolify env vars
   - Must match the database container's configured user/pass

4. NETWORKING
   - Web app and DB must be on same Docker network
   - In Coolify, they should auto-connect if in same project

5. DATABASE NOT INITIALIZED
   - If connection works but tables are missing:
     Visit /setup.php to create tables
        </pre>
    </div>

    <p style="text-align: center; margin-top: 2rem; opacity: 0.5; font-size: 0.8rem;">
        EduPortal Diagnostics | Developer: Alwen T. Casagan
    </p>
</body>
</html>

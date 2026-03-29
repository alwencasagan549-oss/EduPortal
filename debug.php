<?php
/**
 * EDUPORTAL LMS - EMERGENCY DIAGNOSTICS
 * USE THIS TO PINPOINT 500 ERRORS ON INFINITYFREE
 */

// Enable All Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🚀 EduPortal Deployment Diagnostics</h1>";
echo "<p>Checking system compatibility and configuration...</p>";
echo "<hr>";

// 1. Check PHP Version
echo "### 1. PHP Environment<br>";
echo "Current PHP Version: " . phpversion() . "<br>";
if (version_compare(phpversion(), '7.4.0', '<')) {
    echo "<span style='color:red;'>⚠️ WARNING: PHP version is below 7.4. Recommend 8.1+ for best performance.</span><br>";
} else {
    echo "<span style='color:green;'>✅ PHP Version Compatible.</span><br>";
}

echo "<hr>";

// 2. Check config/credentials.php
echo "### 2. Security Infrastructure<br>";
$cred_file = __DIR__ . '/config/credentials.php';
if (file_exists($cred_file)) {
    echo "<span style='color:green;'>✅ config/credentials.php found.</span><br>";
    require_once $cred_file;
    
    // Test Database Connectivity
    echo "<br>### 3. Database Connectivity<br>";
    if (defined('SECURE_DB_HOST')) {
        try {
            $conn = @new mysqli(SECURE_DB_HOST, SECURE_DB_USER, SECURE_DB_PASS, SECURE_DB_NAME);
            if ($conn->connect_error) {
                echo "<span style='color:red;'>❌ Connection Failed: " . $conn->connect_error . "</span><br>";
                echo "Check if your DB_HOST and DB_USER match the InfinityFree Control Panel exactly.<br>";
            } else {
                echo "<span style='color:green;'>✅ Database Connected Successfully!</span><br>";
                $conn->close();
            }
        } catch (Exception $e) {
            echo "<span style='color:red;'>❌ Critical Error: " . $e->getMessage() . "</span><br>";
        }
    } else {
        echo "<span style='color:red;'>⚠️ Error: SECURE_DB_HOST is not defined in credentials.php.</span><br>";
    }
} else {
    echo "<span style='color:red;'>❌ ERROR: config/credentials.php is MISSING.</span><br>";
    echo "Please create it using the implementation plan instructions.<br>";
}

echo "<hr>";

// 3. Recommended Actions
echo "### 📝 Recommended Actions<br>";
echo "1. If everything is green but you still see a 500 error on other pages, check your <b>.htaccess</b> file.<br>";
echo "2. Ensure all folders like <b>uploads/</b> have write permissions (755).<br>";
echo "3. <b>IMPORTANT: Delete this file (debug.php) after fixing the issue for security.</b><br>";
?>

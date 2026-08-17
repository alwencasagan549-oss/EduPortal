<?php
/**
 * --------------------------------------------------------------------------------
 * EDUPORTAL LMS - CORE DATABASE ENGINE (PDO / PostgreSQL)
 * --------------------------------------------------------------------------------
 */

// Render/production: use environment variables if credentials.php is absent
if (!file_exists(__DIR__ . '/credentials.php')) {
    define('SECURE_DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('SECURE_DB_USER', getenv('DB_USER') ?: 'root');
    define('SECURE_DB_PASS', getenv('DB_PASS') ?: '');
    define('SECURE_DB_NAME', getenv('DB_NAME') ?: 'edu_portal');
    define('SMTP_HOST', getenv('SMTP_HOST') ?: 'ssl://smtp.gmail.com');
    define('SMTP_PORT', getenv('SMTP_PORT') ?: 465);
    define('SMTP_USER', getenv('SMTP_USER') ?: '');
    define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
    define('PLATFORM_NAME', getenv('PLATFORM_NAME') ?: 'EduPortal LMS');
    define('SITE_URL', getenv('SITE_URL') ?: 'http://localhost/Eduportal');
} else {
    require_once __DIR__ . '/credentials.php';
}

define('DB_HOST', SECURE_DB_HOST);
define('DB_USER', SECURE_DB_USER);
define('DB_PASS', SECURE_DB_PASS);
define('DB_NAME', SECURE_DB_NAME);

// Harden Session Security (Auth Shield)
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Bind session to IP + User-Agent to prevent hijacking
if (empty($_SESSION['_ip_fingerprint']) && !empty($_SESSION['user_id'])) {
    $_SESSION['_ip_fingerprint'] = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function verifySessionBinding() {
    if (empty($_SESSION['_ip_fingerprint']) || empty($_SESSION['user_id'])) {
        return true;
    }
    $current = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return hash_equals($_SESSION['_ip_fingerprint'], $current);
}

function bindSession() {
    $_SESSION['_ip_fingerprint'] = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

// Send HTTP security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token() {
    return $_SESSION['csrf_token'] ?? '';
}

function validate_csrf($token) {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

class EduPortalDB {
    private $pdo;

    public function __construct($host, $user, $pass, $dbname) {
        $dsn = "pgsql:host=$host;dbname=$dbname";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function prepare($sql) {
        return new EduPortalStmt($this->pdo->prepare($sql));
    }

    public function query($sql) {
        $stmt = $this->pdo->query($sql);
        return $stmt ? new EduPortalResult($stmt) : false;
    }

    public function exec($sql) {
        return $this->pdo->exec($sql);
    }

    public function close() {
        $this->pdo = null;
    }

    public function getPDO() {
        return $this->pdo;
    }
}

class EduPortalStmt {
    private $stmt;

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function execute($params = null) {
        if ($params === null) return $this->stmt->execute();
        if (is_string($params)) {
            $params = array_slice(func_get_args(), 1);
        }
        return $this->stmt->execute($params);
    }

    public function get_result() {
        return new EduPortalResult($this->stmt);
    }

    public function fetch_assoc() {
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetch_all($style = PDO::FETCH_ASSOC) {
        return $this->stmt->fetchAll($style);
    }

    public function rowCount() {
        return $this->stmt->rowCount();
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    public function bind_param() {
        return true;
    }
}

class EduPortalResult {
    private $stmt;

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function fetch_assoc() {
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetch_all($style = PDO::FETCH_ASSOC) {
        return $this->stmt->fetchAll($style);
    }

    public function num_rows() {
        return $this->stmt->rowCount();
    }
}

function getDBConnection() {
    static $conn;
    if ($conn === null) {
        $conn = new EduPortalDB(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    }
    return $conn;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && verifySessionBinding();
}

function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true && verifySessionBinding();
}

function base_path($path = '') {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $depth = substr_count($base, '\\') + substr_count($base, '/');
    return str_repeat('../', max(0, $depth - 0)) . ltrim($path, '/');
}

function requireLogin() {
    if (!isLoggedIn() && !isAdminLoggedIn()) {
        header('Location: /session_expired.php');
        exit();
    }
}

function getUserRole() {
    if (isAdminLoggedIn()) {
        return "admin";
    }
    return $_SESSION['user_role'] ?? '';
}

function sanitize($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}
?>

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
} else {
    require_once __DIR__ . '/credentials.php';
}

define('DB_HOST', SECURE_DB_HOST);
define('DB_USER', SECURE_DB_USER);
define('DB_PASS', SECURE_DB_PASS);
define('DB_NAME', SECURE_DB_NAME);

// Harden Session Security (Auth Shield)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

class EduPortalDB {
    private $pdo;

    public function __construct($host, $user, $pass, $dbname) {
        $dsn = "pgsql:host=$host;dbname=$dbname";
        $this->pdo = new PDO($dsn, $user, $pass);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public function prepare($sql) {
        return new EduPortalStmt($this->pdo->prepare($sql));
    }

    public function query($sql) {
        $stmt = $this->pdo->query($sql);
        return $stmt ? new EduPortalResult($stmt) : false;
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
    return isset($_SESSION['user_id']);
}

function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireLogin() {
    if (!isLoggedIn() && !isAdminLoggedIn()) {
        header('Location: index.php');
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

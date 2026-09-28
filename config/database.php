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
    define('SECURE_DB_PORT', getenv('DB_PORT') ?: '17436');
    define('SECURE_DB_SSL_MODE', getenv('DB_SSL_MODE') ?: 'require');
    define('PLATFORM_NAME', getenv('PLATFORM_NAME') ?: 'EduPortal LMS');
    define('SITE_URL', getenv('SITE_URL') ?: 'http://localhost/Eduportal');
} else {
    require_once __DIR__ . '/credentials.php';
}

define('DB_HOST', SECURE_DB_HOST);
define('DB_USER', SECURE_DB_USER);
define('DB_PASS', SECURE_DB_PASS);
define('DB_NAME', SECURE_DB_NAME);
define('DB_PORT', SECURE_DB_PORT);
define('DB_SSL_MODE', SECURE_DB_SSL_MODE);

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

/**
 * Fingerprint used to detect a session cookie replayed from a different
 * client. Computed on demand so it always reflects the current request.
 */
function session_fingerprint(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/**
 * Compares the stored fingerprint against the current request.
 *
 * The previous implementation rewrote $_SESSION['_ip_fingerprint'] on every
 * request and then compared it against a freshly computed value, so the check
 * was always hash(x) === hash(x) and detected nothing. The fingerprint is now
 * written exactly once, at bindSession() during login.
 *
 * Binds on IP + User-Agent. Note that mobile carriers rotate IP addresses, so
 * a strict IP match will sign students out; widen this deliberately if that
 * becomes a problem rather than dropping the check entirely.
 */
function verifySessionBinding(): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $stored = $_SESSION['_ip_fingerprint'] ?? null;
    if (!is_string($stored) || $stored === '') {
        // Session predates the binding (or the column was cleared): adopt the
        // current client rather than signing everyone out on deploy.
        bindSession();
        return true;
    }

    return hash_equals($stored, session_fingerprint());
}

function bindSession(): void
{
    $_SESSION['_ip_fingerprint'] = session_fingerprint();
}

// Send HTTP security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cache-Control: no-store, max-age=0');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-site');
    header('X-XSS-Protection: 0');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; img-src 'self' data: blob:; connect-src 'self' https://*.r2.cloudflarestorage.com; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; upgrade-insecure-requests; trusted-types eduportal;");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_prev'] = '';
}

function normalize_teacher_subject(string $subject): string
{
    $normalized = preg_replace('/\s+/u', ' ', $subject);
    $subject = trim(is_string($normalized) ? $normalized : $subject);
    return strcasecmp($subject, 'programming') === 0 ? 'Programming' : $subject;
}

function csrf_token() {
    return $_SESSION['csrf_token'] ?? '';
}

function validate_csrf($token) {
    $current = $_SESSION['csrf_token'] ?? '';
    $previous = $_SESSION['csrf_token_prev'] ?? '';
    if (hash_equals($current, $token) || ($previous !== '' && hash_equals($previous, $token))) {
        return true;
    }
    return false;
}

function rotate_csrf() {
    $_SESSION['csrf_token_prev'] = $_SESSION['csrf_token'] ?? '';
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

class EduPortalDB {
    private $pdo;

    public function __construct($host, $user, $pass, $dbname, $port = '5432', $sslmode = '') {
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};connect_timeout=5";
        if ($sslmode !== '') {
            $dsn .= ";sslmode=$sslmode";
        }
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
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

    public function getDriverName() {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
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

    public function fetchColumn($column = 0) {
        return $this->stmt->fetchColumn($column);
    }

    public function fetch_all($style = PDO::FETCH_ASSOC) {
        return $this->stmt->fetchAll($style);
    }

    public function rowCount() {
        return $this->stmt->rowCount();
    }
}

class EduPortalResult {
    private $stmt;
    private $cached_rows = null;
    private int $cursor = 0;

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    public function fetch_assoc() {
        if ($this->cached_rows !== null) {
            // Integer cursor rather than array_shift: shift() is O(n) and
            // reindexes the whole array, so mixing num_rows() with
            // fetch_assoc() turned every row loop into O(n^2).
            $row = $this->cached_rows[$this->cursor] ?? null;
            $this->cursor++;
            return $row === null ? false : $row;
        }
        return $this->stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetch_all($style = PDO::FETCH_ASSOC) {
        if ($this->cached_rows !== null) {
            return array_slice($this->cached_rows, $this->cursor);
        }
        return $this->stmt->fetchAll($style);
    }

    public function num_rows() {
        if ($this->cached_rows === null) {
            $this->cached_rows = $this->stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return count($this->cached_rows);
    }
}

function getDBConnection() {
    static $conn;
    if ($conn === null) {
        $conn = new EduPortalDB(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, DB_SSL_MODE);
    }
    return $conn;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && verifySessionBinding();
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /session_expired.php');
        exit();
    }
}

function getUserRole() {
    return $_SESSION['user_role'] ?? '';
}

function sanitize($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}
?>

<?php
/**
 * --------------------------------------------------------------------------------
 * EDUPORTAL LMS - CORE DATABASE ENGINE
 * --------------------------------------------------------------------------------
 * @author    Alwen T. Casagan
 * @role      Web Developer / Systems Architect
 * @copyright 2026 Alwen T. Casagan. All rights reserved.
 * 
 * PROPRIETARY AND CONFIDENTIAL:
 * Unauthorized copying, modification, or distribution of this file, via any 
 * medium, is strictly prohibited. This code is unique to the EduPortal 
 * ecosystem and is protected by academic and digital copyright laws.
 * --------------------------------------------------------------------------------
 */
// Load Secure Credentials (local development) or use environment variables (Render/production)
if (file_exists(__DIR__ . '/credentials.php')) {
    require_once __DIR__ . '/credentials.php';
}

// Database configuration (env vars for production, credentials.php for local)
if (!defined('SECURE_DB_HOST')) define('SECURE_DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('SECURE_DB_USER')) define('SECURE_DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('SECURE_DB_PASS')) define('SECURE_DB_PASS', getenv('DB_PASS') ?: '');
if (!defined('SECURE_DB_NAME')) define('SECURE_DB_NAME', getenv('DB_NAME') ?: 'edu_portal');

define('DB_HOST', SECURE_DB_HOST);
define('DB_USER', SECURE_DB_USER);
define('DB_PASS', SECURE_DB_PASS);
define('DB_NAME', SECURE_DB_NAME);

// SMTP constants (from credentials.php locally, env vars on Render)
if (!defined('SMTP_HOST')) define('SMTP_HOST', getenv('SMTP_HOST') ?: 'ssl://smtp.gmail.com');
if (!defined('SMTP_PORT')) define('SMTP_PORT', getenv('SMTP_PORT') ?: 465);
if (!defined('SMTP_USER')) define('SMTP_USER', getenv('SMTP_USER') ?: '');
if (!defined('SMTP_PASS')) define('SMTP_PASS', getenv('SMTP_PASS') ?: '');

// Harden Session Security (Auth Shield)
if (session_status() === PHP_SESSION_NONE) {
    // Only send cookies over HTTPS if reachable, but always set HttpOnly and SameSite
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

/**
 * Create database connection
 * @return mysqli Database connection object
 */
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Set charset to UTF-8
    $conn->set_charset("utf8mb4");
    
    return $conn;
}

/**
 * Check if user is logged in
 * @return bool True if logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if admin is logged in (legacy support)
 * @return bool True if admin logged in
 */
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Require user to be logged in
 * @return void Redirects to login if not logged in
 */
function requireLogin() {
    if (!isLoggedIn() && !isAdminLoggedIn()) {
        header('Location: index.php');
        exit();
    }
}

/**
 * Get current user's role
 * @return string User role (admin, teacher, student)
 */
function getUserRole() {
    if (isAdminLoggedIn()) {
        return "admin";
    }
    return $_SESSION['user_role'] ?? '';
}

/**
 * Sanitize input data
 * @param string $data Input to sanitize
 * @return string Sanitized input
 */
function sanitize($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}
?>
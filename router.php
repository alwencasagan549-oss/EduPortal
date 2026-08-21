<?php
// router.php - URL Rewriter for PHP Built-in Server (SnapDeploy)
// Handles clean URLs and static file serving

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// Serve existing static files directly
if ($path !== '/' && $path !== '' && file_exists($file) && is_file($file)) {
    return false;
}

// Route all other requests through index.php
require_once __DIR__ . '/index.php';

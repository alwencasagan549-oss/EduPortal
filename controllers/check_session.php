<?php
require_once __DIR__ . '/../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect debug tool to home — session data must not be publicly exposed
header('Location: ../index.php');
exit();
?>

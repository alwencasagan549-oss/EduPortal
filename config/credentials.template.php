<?php
/**
 * EduPortal Security Layer: Credentials TEMPLATE
 * 
 * Instructions:
 * 1. Copy this file to 'config/credentials.php'
 * 2. Fill in your actual Gmail and Database details.
 * 3. Never commit 'config/credentials.php' to your public repository!
 * 
 * -> FOR INFINITYFREE DEPLOYMENT:
 * -> Replace localhost with your InfinityFree DB Host.
 * -> Replace student/teacher with your InfinityFree DB Username.
 */

// Database Credentials (Fill with InfinityFree Details)
define('SECURE_DB_HOST', 'YOUR_DB_HOST');
define('SECURE_DB_USER', 'YOUR_DB_USER');
define('SECURE_DB_PASS', 'YOUR_DB_PASSWORD');
define('SECURE_DB_NAME', 'YOUR_DB_NAME');

// SMTP / Email Hub (Gmail App Password)
define('SMTP_HOST', 'ssl://smtp.gmail.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'your-gmail@gmail.com');
define('SMTP_PASS', '16-CHARACTER-APP-PASSWORD'); 

// Platform Metadata
define('PLATFORM_NAME', 'EduPortal LMS');
define('ADMIN_EMAIL', 'your-gmail@gmail.com');

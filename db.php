<?php
/**
 * Database Connection Handler
 * Checks for config.php and establishes PDO connection
 * Redirects to installer if config is missing
 */

// Check for Environment Variables (Cloud Deployment) first
$db_host = getenv('DB_HOST');
$db_name = getenv('DB_NAME');
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASSWORD') ?: getenv('DB_PASS'); // Support both standard names

if ($db_host && $db_name && $db_user) {
    // Cloud Environment Detected - Config is already ready via Env Vars
} elseif (file_exists(__DIR__ . '/config.php')) {
    // Local Environment - Load from config.php
    require_once __DIR__ . '/config.php';
    $db_host = defined('DB_HOST') ? DB_HOST : null;
    $db_name = defined('DB_NAME') ? DB_NAME : null;
    $db_user = defined('DB_USER') ? DB_USER : null;
    $db_pass = defined('DB_PASS') ? DB_PASS : null;
} else {
    // No config found -> Redirect to Installer
    header('Location: install.php');
    exit;
}

try {
    // Create PDO connection - support both MySQL and SQLite
    if ($db_host === 'sqlite') {
        // SQLite mode for demo
        $pdo = new PDO('sqlite:' . $db_name);
    } else {
        // MySQL mode for production
        $dsn = "mysql:host=" . $db_host . ";dbname=" . $db_name . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass);
    }
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
} catch (PDOException $e) {
    // Display user-friendly error message in Thai
    die("เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage());
}

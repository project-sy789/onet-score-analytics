<?php
/**
 * Database Connection Handler
 * Checks for config.php and establishes PDO connection
 * Redirects to installer if config is missing
 */

// Check if config.php exists
if (!file_exists(__DIR__ . '/config.php')) {
    // Redirect to installer
    header('Location: install.php');
    exit;
}

// Include database configuration
require_once __DIR__ . '/config.php';

try {
    // Create PDO connection - support both MySQL and SQLite
    if (DB_HOST === 'sqlite') {
        // SQLite mode for demo
        $pdo = new PDO('sqlite:' . DB_NAME);
    } else {
        // MySQL mode for production
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
    }
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    
} catch (PDOException $e) {
    // Display user-friendly error message in Thai
    die("เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage());
}

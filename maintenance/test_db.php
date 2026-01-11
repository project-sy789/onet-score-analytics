<?php
/**
 * Database Test Script
 * Upload this to hosting to check database configuration
 */

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Database Test</h1>";

// Check if config.php exists
if (!file_exists(__DIR__ . '/config.php')) {
    die("<p style='color:red'>❌ config.php not found!</p>");
}

require_once __DIR__ . '/config.php';

echo "<h2>Configuration</h2>";
echo "<pre>";
echo "DB_HOST: " . DB_HOST . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "DB_USER: " . DB_USER . "\n";
echo "DB_PASS: " . str_repeat('*', strlen(DB_PASS)) . "\n";
echo "</pre>";

try {
    // Connect to database
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "<p style='color:green'>✅ Database connection successful!</p>";
    
    // Get database info
    echo "<h2>Database Information</h2>";
    echo "<pre>";
    
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Driver: $driver\n";
    
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "Version: $version\n";
    
    // Check if it's MySQL or MariaDB
    $is_mariadb = stripos($version, 'MariaDB') !== false;
    echo "Type: " . ($is_mariadb ? 'MariaDB' : 'MySQL') . "\n";
    
    echo "</pre>";
    
    // Test INSERT ON DUPLICATE KEY UPDATE
    echo "<h2>Testing SQL Syntax</h2>";
    
    // Create test table
    $pdo->exec("DROP TABLE IF EXISTS test_import");
    $pdo->exec("
        CREATE TABLE test_import (
            id VARCHAR(10) PRIMARY KEY,
            name VARCHAR(50)
        )
    ");
    
    echo "<p>✅ Test table created</p>";
    
    // Test MySQL syntax
    try {
        $stmt = $pdo->prepare("
            INSERT INTO test_import (id, name)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE name = ?
        ");
        $stmt->execute(['1', 'Test', 'Updated']);
        echo "<p style='color:green'>✅ ON DUPLICATE KEY UPDATE works!</p>";
        
        // Try again to test update
        $stmt->execute(['1', 'Test', 'Updated Again']);
        echo "<p style='color:green'>✅ Duplicate update works!</p>";
        
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ ON DUPLICATE KEY UPDATE failed: " . $e->getMessage() . "</p>";
    }
    
    // Clean up
    $pdo->exec("DROP TABLE test_import");
    echo "<p>✅ Test table dropped</p>";
    
    echo "<h2>Conclusion</h2>";
    echo "<p style='color:green; font-weight:bold'>✅ Your hosting supports MySQL with ON DUPLICATE KEY UPDATE syntax!</p>";
    echo "<p>You can safely use the import.php file.</p>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>❌ Database Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>

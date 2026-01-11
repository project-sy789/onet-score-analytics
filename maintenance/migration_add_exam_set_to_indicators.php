<?php
/**
 * Migration: Add exam_set to indicators table
 * Run this once: https://yourdomain.com/ONET/migration_add_exam_set_to_indicators.php
 */

require_once __DIR__ . '/db.php';

echo "<h2>🔄 Adding exam_set to indicators table...</h2>\n";

try {
    // Check database type
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $is_mysql = ($driver === 'mysql');
    
    echo "<p>Database: " . strtoupper($driver) . "</p>\n";
    
    // Add exam_set column to indicators table
    echo "<p>📋 Adding exam_set column to indicators table...</p>\n";
    
    if ($is_mysql) {
        $pdo->exec("ALTER TABLE indicators ADD COLUMN exam_set VARCHAR(50) DEFAULT 'default' AFTER grade_level");
    } else {
        // SQLite doesn't support ALTER COLUMN, need to recreate table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS indicators_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code VARCHAR(50) UNIQUE NOT NULL,
                description TEXT,
                subject VARCHAR(100),
                grade_level VARCHAR(10),
                exam_set VARCHAR(50) DEFAULT 'default'
            )
        ");
        
        $pdo->exec("INSERT INTO indicators_new SELECT id, code, description, subject, grade_level, 'default' FROM indicators");
        $pdo->exec("DROP TABLE indicators");
        $pdo->exec("ALTER TABLE indicators_new RENAME TO indicators");
    }
    
    echo "<p>✅ Column 'exam_set' added to indicators table</p>\n";
    
    // Verify
    echo "<p>🔍 Verifying schema...</p>\n";
    
    if ($is_mysql) {
        $stmt = $pdo->query("DESCRIBE indicators");
    } else {
        $stmt = $pdo->query("PRAGMA table_info(indicators)");
    }
    
    echo "<p>Indicators table schema:</p><ul>\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($is_mysql) {
            echo "<li>{$row['Field']} ({$row['Type']})</li>\n";
        } else {
            echo "<li>{$row['name']} ({$row['type']})</li>\n";
        }
    }
    echo "</ul>\n";
    
    echo "<h3>✅ Migration completed successfully!</h3>\n";
    echo "<p>📝 Next: Update master indicators CSV to include exam_set column</p>\n";
    echo "<p>Format: <code>code,description,subject,grade_level,exam_set</code></p>\n";
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>\n";
}

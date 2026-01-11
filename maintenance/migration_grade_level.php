<?php
/**
 * Migration Script: Add grade_level column to indicators table
 * This allows separating indicators by grade level (ม.3, ม.6, etc.)
 */

require_once __DIR__ . '/db.php';

try {
    echo "🔄 Starting migration: Add grade_level to indicators table...\n\n";
    
    // Detect database type
    $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $is_mysql = ($db_driver === 'mysql');
    
    echo "Database: " . ($is_mysql ? "MySQL" : "SQLite") . "\n";
    
    // Check if column already exists
    if ($is_mysql) {
        $check = $pdo->query("SHOW COLUMNS FROM indicators LIKE 'grade_level'")->fetch();
    } else {
        $check = $pdo->query("PRAGMA table_info(indicators)")->fetchAll(PDO::FETCH_ASSOC);
        $check = array_filter($check, function($col) {
            return $col['name'] === 'grade_level';
        });
    }
    
    if (!empty($check)) {
        echo "✅ Column 'grade_level' already exists. Skipping migration.\n";
        exit(0);
    }
    
    // Add grade_level column
    echo "Adding 'grade_level' column...\n";
    
    if ($is_mysql) {
        $pdo->exec("ALTER TABLE indicators ADD COLUMN grade_level VARCHAR(10) DEFAULT NULL");
    } else {
        $pdo->exec("ALTER TABLE indicators ADD COLUMN grade_level TEXT DEFAULT NULL");
    }
    
    echo "✅ Column added successfully!\n\n";
    
    // Verify the change
    echo "Verifying schema...\n";
    if ($is_mysql) {
        $columns = $pdo->query("SHOW COLUMNS FROM indicators")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
    } else {
        $columns = $pdo->query("PRAGMA table_info(indicators)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  - {$col['name']} ({$col['type']})\n";
        }
    }
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\n📝 Next steps:\n";
    echo "1. Update your CSV files to include grade_level column\n";
    echo "2. Re-import indicators with grade level information\n";
    echo "3. Format: code,description,subject,grade_level\n";
    echo "   Example: ว1.1 ม.3/1,อธิบาย...,วิทยาศาสตร์,ม.3\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

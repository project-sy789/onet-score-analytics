<?php
/**
 * Migration Script: Add exam_set field to questions and scores tables
 * This allows tracking multiple exam rounds (Pre O-NET, O-NET) separately
 */

require_once __DIR__ . '/db.php';

try {
    echo "🔄 Starting migration: Add exam_set to questions and scores tables...\n\n";
    
    // Detect database type
    $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $is_mysql = ($db_driver === 'mysql');
    
    echo "Database: " . ($is_mysql ? "MySQL" : "SQLite") . "\n\n";
    
    // ========================================
    // 1. Add exam_set to questions table
    // ========================================
    echo "📋 Step 1: Adding exam_set to questions table...\n";
    
    // Check if column already exists
    if ($is_mysql) {
        $check = $pdo->query("SHOW COLUMNS FROM questions LIKE 'exam_set'")->fetch();
    } else {
        $check = $pdo->query("PRAGMA table_info(questions)")->fetchAll(PDO::FETCH_ASSOC);
        $check = array_filter($check, function($col) {
            return $col['name'] === 'exam_set';
        });
    }
    
    if (!empty($check)) {
        echo "  ⚠️  Column 'exam_set' already exists in questions table. Skipping.\n";
    } else {
        if ($is_mysql) {
            $pdo->exec("ALTER TABLE questions ADD COLUMN exam_set VARCHAR(50) DEFAULT 'default'");
        } else {
            $pdo->exec("ALTER TABLE questions ADD COLUMN exam_set TEXT DEFAULT 'default'");
        }
        echo "  ✅ Column 'exam_set' added to questions table\n";
    }
    
    // ========================================
    // 2. Add exam_set to scores table
    // ========================================
    echo "\n📊 Step 2: Adding exam_set to scores table...\n";
    
    // Check if column already exists
    if ($is_mysql) {
        $check = $pdo->query("SHOW COLUMNS FROM scores LIKE 'exam_set'")->fetch();
    } else {
        $check = $pdo->query("PRAGMA table_info(scores)")->fetchAll(PDO::FETCH_ASSOC);
        $check = array_filter($check, function($col) {
            return $col['name'] === 'exam_set';
        });
    }
    
    if (!empty($check)) {
        echo "  ⚠️  Column 'exam_set' already exists in scores table. Skipping.\n";
    } else {
        if ($is_mysql) {
            $pdo->exec("ALTER TABLE scores ADD COLUMN exam_set VARCHAR(50) DEFAULT 'default'");
        } else {
            $pdo->exec("ALTER TABLE scores ADD COLUMN exam_set TEXT DEFAULT 'default'");
        }
        echo "  ✅ Column 'exam_set' added to scores table\n";
    }
    
    // ========================================
    // 3. Verify the changes
    // ========================================
    echo "\n🔍 Step 3: Verifying schema changes...\n\n";
    
    echo "Questions table schema:\n";
    if ($is_mysql) {
        $columns = $pdo->query("SHOW COLUMNS FROM questions")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
    } else {
        $columns = $pdo->query("PRAGMA table_info(questions)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  - {$col['name']} ({$col['type']})\n";
        }
    }
    
    echo "\nScores table schema:\n";
    if ($is_mysql) {
        $columns = $pdo->query("SHOW COLUMNS FROM scores")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  - {$col['Field']} ({$col['Type']})\n";
        }
    } else {
        $columns = $pdo->query("PRAGMA table_info(scores)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "  - {$col['name']} ({$col['type']})\n";
        }
    }
    
    // ========================================
    // 4. Summary
    // ========================================
    echo "\n✅ Migration completed successfully!\n\n";
    echo "📝 Next steps:\n";
    echo "1. Update import functions to handle exam_set field\n";
    echo "2. Update CSV format to include exam_set column\n";
    echo "3. Update UI to add exam_set filter\n";
    echo "4. Update queries to filter/aggregate by exam_set\n\n";
    echo "💡 Naming convention for exam_set:\n";
    echo "   - Pre-ONET-{year}-R{round} (e.g., Pre-ONET-2566-R1)\n";
    echo "   - ONET-{year} (e.g., ONET-2566)\n";
    echo "   - Mock-{year}-{name} (e.g., Mock-2566-Science)\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

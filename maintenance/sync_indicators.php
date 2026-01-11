<?php
/**
 * Sync Script: Populate indicators table from question_indicators
 * This script extracts unique indicator codes from question_indicators table
 * and creates records in the indicators table with proper subject mapping
 */

require_once __DIR__ . '/db.php';

echo "🔄 Starting indicator sync...\n\n";

try {
    // Step 1: Check if question_indicators table exists and has data
    echo "📊 Step 1: Checking question_indicators table...\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM question_indicators");
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        echo "   ⚠️  No data found in question_indicators table\n";
        echo "   💡 Please import indicator data first via the Import page\n";
        exit(0);
    }
    
    echo "   ✅ Found $count question-indicator relationships\n\n";
    
    // Step 2: Get unique indicator codes with their subjects
    echo "📊 Step 2: Extracting unique indicators...\n";
    
    $stmt = $pdo->query("
        SELECT DISTINCT 
            qi.indicator_code,
            q.subject
        FROM question_indicators qi
        LEFT JOIN questions q ON qi.question_number = q.question_number
        WHERE qi.indicator_code IS NOT NULL AND qi.indicator_code != ''
        ORDER BY qi.indicator_code
    ");
    
    $indicators_data = $stmt->fetchAll();
    
    echo "   ✅ Found " . count($indicators_data) . " unique indicators\n\n";
    
    // Step 3: Create indicators table if not exists
    echo "📊 Step 3: Creating indicators table...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS indicators (
            code VARCHAR(50) PRIMARY KEY,
            description TEXT,
            subject VARCHAR(100)
        )
    ");
    
    echo "   ✅ Table created/verified\n\n";
    
    // Step 4: Insert or update indicators
    echo "📊 Step 4: Syncing indicators...\n";
    
    // For MySQL, use INSERT ... ON DUPLICATE KEY UPDATE
    // For SQLite, use INSERT OR REPLACE
    $is_mysql = (DB_HOST !== 'sqlite');
    
    if ($is_mysql) {
        $insert_stmt = $pdo->prepare("
            INSERT INTO indicators (code, description, subject)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                subject = VALUES(subject)
        ");
    } else {
        $insert_stmt = $pdo->prepare("
            INSERT OR REPLACE INTO indicators (code, description, subject)
            VALUES (?, ?, ?)
        ");
    }
    
    $inserted = 0;
    $updated = 0;
    
    foreach ($indicators_data as $ind) {
        $code = $ind['indicator_code'];
        $subject = $ind['subject'] ?: 'ไม่ระบุวิชา';
        $description = "ตัวชี้วัด $code";
        
        // Check if exists
        $check = $pdo->prepare("SELECT COUNT(*) FROM indicators WHERE code = ?");
        $check->execute([$code]);
        $exists = $check->fetchColumn() > 0;
        
        $insert_stmt->execute([$code, $description, $subject]);
        
        if ($exists) {
            $updated++;
        } else {
            $inserted++;
        }
    }
    
    echo "   ✅ Inserted $inserted new indicators\n";
    echo "   ✅ Updated $updated existing indicators\n\n";
    
    // Step 5: Verify results
    echo "📊 Step 5: Verifying results...\n";
    
    $count_ind = $pdo->query("SELECT COUNT(*) FROM indicators")->fetchColumn();
    $count_rel = $pdo->query("SELECT COUNT(*) FROM question_indicators")->fetchColumn();
    
    echo "   ✅ Total indicators in database: $count_ind\n";
    echo "   ✅ Total question-indicator relationships: $count_rel\n\n";
    
    // Step 6: Show statistics by subject
    echo "📊 Step 6: Statistics by subject...\n";
    
    $stats = $pdo->query("
        SELECT 
            subject,
            COUNT(*) as indicator_count
        FROM indicators
        GROUP BY subject
        ORDER BY subject
    ")->fetchAll();
    
    foreach ($stats as $stat) {
        echo "   📚 {$stat['subject']}: {$stat['indicator_count']} indicators\n";
    }
    
    echo "\n";
    
    // Step 7: Show sample data
    echo "📋 Sample indicators:\n";
    $samples = $pdo->query("SELECT code, subject FROM indicators ORDER BY code LIMIT 10")->fetchAll();
    foreach ($samples as $s) {
        echo "   - {$s['code']} ({$s['subject']})\n";
    }
    
    echo "\n✅ Sync completed successfully!\n";
    echo "🎉 You can now view the Indicator Coverage Analysis on the dashboard.\n";
    echo "🔗 Go to: " . (isset($_SERVER['HTTP_HOST']) ? "http://{$_SERVER['HTTP_HOST']}" . dirname($_SERVER['PHP_SELF']) : "index.php") . "\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "\n💡 Troubleshooting:\n";
    echo "   1. Make sure you have imported question and indicator data\n";
    echo "   2. Check that the question_indicators table exists\n";
    echo "   3. Verify database connection in config.php\n";
    exit(1);
}

<?php
require_once 'db.php';

echo "<h2>Migration: Add grade_level to questions table</h2>";

try {
    $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database driver: $db_driver<br>";
    
    // 1. Add column if not exists
    $col_exists = false;
    try {
        if ($db_driver === 'mysql') {
            $pdo->query("SELECT grade_level FROM questions LIMIT 1");
        } else {
            // SQLite
            $stmt = $pdo->query("PRAGMA table_info(questions)");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['name'] === 'grade_level') {
                    $col_exists = true;
                    break;
                }
            }
        }
        if ($db_driver === 'mysql') $col_exists = true; // If query didn't fail
    } catch (Exception $e) {
        $col_exists = false;
    }
    
    if (!$col_exists) {
        echo "Adding grade_level column... ";
        if ($db_driver === 'mysql') {
            $pdo->exec("ALTER TABLE questions ADD COLUMN grade_level VARCHAR(20) DEFAULT NULL AFTER subject");
        } else {
            $pdo->exec("ALTER TABLE questions ADD COLUMN grade_level TEXT DEFAULT NULL");
        }
        echo "✅ Done.<br>";
    } else {
        echo "Column grade_level already exists. Skipping add.<br>";
    }
    
    // 2. Backfill data from indicators
    echo "Backfilling grade_level from indicators... ";
    
    // Get all questions
    $stmt = $pdo->query("SELECT id FROM questions WHERE grade_level IS NULL OR grade_level = ''");
    $questions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $count = 0;
    foreach ($questions as $qid) {
        // Find associated indicator's grade_level
        $stmt = $pdo->prepare("
            SELECT i.grade_level 
            FROM indicators i 
            JOIN question_indicators qi ON i.id = qi.indicator_id 
            WHERE qi.question_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$qid]);
        $grade = $stmt->fetchColumn();
        
        if ($grade) {
            $update = $pdo->prepare("UPDATE questions SET grade_level = ? WHERE id = ?");
            $update->execute([$grade, $qid]);
            $count++;
        }
    }
    
    echo "✅ Updated $count questions.<br>";
    echo "<h3>Migration Completed Successfully</h3>";
    
} catch (Exception $e) {
    echo "<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>";
}
?>

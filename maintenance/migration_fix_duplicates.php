<?php
require_once 'db.php';

echo "<h2>Migration: Fix Duplicates & Add Constraints</h2>";

try {
    $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database driver: $db_driver<br>";
    
    // ==========================================
    // 1. CLEANUP DUPLICATE SCORES
    // ==========================================
    echo "<h3>1. Cleaning up Duplicate Scores...</h3>";
    
    if ($db_driver === 'mysql') {
        // MySQL cleanup logic
        // Keep the row with MAX id for each group
        $sql = "
            DELETE s1 FROM scores s1
            INNER JOIN scores s2 
            WHERE s1.id < s2.id 
            AND s1.student_id = s2.student_id 
            AND s1.question_number = s2.question_number 
            AND s1.exam_set = s2.exam_set
        ";
        $count = $pdo->exec($sql);
        echo "Deleted $count duplicate score rows.<br>";
        
    } else {
        // SQLite cleanup logic
        // Delete rows where ID is NOT in the list of MAX IDs per group
        $sql = "
            DELETE FROM scores 
            WHERE id NOT IN (
                SELECT MAX(id) 
                FROM scores 
                GROUP BY student_id, question_number, exam_set
            )
        ";
        $count = $pdo->exec($sql);
        echo "Deleted $count duplicate score rows.<br>";
    }
    
    // ==========================================
    // 2. ADD UNIQUE INDEX TO SCORES
    // ==========================================
    echo "<h3>2. Adding Unique Index to Scores...</h3>";
    try {
        if ($db_driver === 'mysql') {
            $pdo->exec("ALTER TABLE scores ADD UNIQUE INDEX idx_unique_score (student_id, question_number, exam_set)");
        } else {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_unique_score ON scores (student_id, question_number, exam_set)");
        }
        echo "✅ Unique Index added to Scores table.<br>";
    } catch (Exception $e) {
        echo "⚠️ Index might already exist or error: " . $e->getMessage() . "<br>";
    }

    // ==========================================
    // 3. CLEANUP DUPLICATE QUESTIONS (Safety)
    // ==========================================
    echo "<h3>3. Cleaning up Duplicate Questions...</h3>";
    
    if ($db_driver === 'mysql') {
        $sql = "
            DELETE q1 FROM questions q1
            INNER JOIN questions q2 
            WHERE q1.id < q2.id 
            AND q1.question_number = q2.question_number 
            AND q1.exam_set = q2.exam_set
        ";
        $count = $pdo->exec($sql);
    } else {
         $sql = "
            DELETE FROM questions 
            WHERE id NOT IN (
                SELECT MAX(id) 
                FROM questions 
                GROUP BY question_number, exam_set
            )
        ";
        $count = $pdo->exec($sql);
    }
    echo "Deleted $count duplicate questions.<br>";

    // ==========================================
    // 4. ADD UNIQUE INDEX TO QUESTIONS
    // ==========================================
    echo "<h3>4. Adding Unique Index to Questions...</h3>";
    try {
        if ($db_driver === 'mysql') {
            $pdo->exec("ALTER TABLE questions ADD UNIQUE INDEX idx_unique_question (question_number, exam_set)");
        } else {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_unique_question ON questions (question_number, exam_set)");
        }
        echo "✅ Unique Index added to Questions table.<br>";
    } catch (Exception $e) {
         echo "⚠️ Index might already exist or error: " . $e->getMessage() . "<br>";
    }

    echo "<h3>🎉 Migration Completed Successfully!</h3>";
    echo "Your data should now be clean and future imports will prevent duplicates.";

} catch (Exception $e) {
    echo "<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>";
}
?>

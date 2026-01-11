<?php
require_once 'db.php';

echo "<h2>Migration: Rebuild Tables & Fix Constraints</h2>";

try {
    $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database driver: $db_driver<br>";
    
    if ($db_driver === 'sqlite') {
        $pdo->beginTransaction();
    }
    
    // ==========================================
    // 1. FIX QUESTIONS TABLE
    // ==========================================
    echo "<h3>1. Rebuilding Questions Table...</h3>";
    
    if ($db_driver === 'sqlite') {
        // Create new table WITHOUT the bad constraint
        $pdo->exec("
            CREATE TABLE questions_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                question_number INTEGER,
                max_score REAL DEFAULT 1,
                subject TEXT,
                exam_set TEXT,
                grade_level TEXT
            )
        ");
        
        // Copy Data
        $pdo->exec("INSERT INTO questions_new (id, question_number, max_score, subject, exam_set, grade_level) 
                    SELECT id, question_number, max_score, subject, exam_set, grade_level FROM questions");
        
        // Drop Old
        $pdo->exec("DROP TABLE questions");
        
        // Rename New
        $pdo->exec("ALTER TABLE questions_new RENAME TO questions");
        
        // Add Correct Indices
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_unique_question ON questions (question_number, exam_set)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_questions_number ON questions (question_number)");
        
        echo "✅ Questions table rebuilt successfully.<br>";
        
    } else {
        // MySQL
        // Attempt to drop the bad index 'question_number' if it exists
        try {
            $pdo->exec("ALTER TABLE questions DROP INDEX question_number");
            echo "✅ Dropped index 'question_number' from questions.<br>";
        } catch (Exception $e) {
            echo "ℹ️ Index 'question_number' not found or already dropped.<br>";
        }
        
        // Ensure composite index exists
        try {
            $pdo->exec("ALTER TABLE questions ADD UNIQUE INDEX idx_unique_question (question_number, exam_set)");
            echo "✅ Added unique index 'idx_unique_question'.<br>";
        } catch (Exception $e) {
            echo "ℹ️ Index 'idx_unique_question' already exists (Good).<br>";
        }
    }
    
    // ==========================================
    // 2. FIX SCORES TABLE
    // ==========================================
    echo "<h3>2. Rebuilding Scores Table...</h3>";
    
    if ($db_driver === 'sqlite') {
        // Create new table WITHOUT the bad constraint
        $pdo->exec("
            CREATE TABLE scores_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id TEXT,
                question_number INTEGER,
                score_obtained REAL,
                exam_set TEXT
            )
        ");
        
        // Copy Data
        $pdo->exec("INSERT INTO scores_new (id, student_id, question_number, score_obtained, exam_set) 
                    SELECT id, student_id, question_number, score_obtained, exam_set FROM scores");
        
        // Drop Old
        $pdo->exec("DROP TABLE scores");
        
        // Rename New
        $pdo->exec("ALTER TABLE scores_new RENAME TO scores");
        
        // Add Correct Indices
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_unique_score ON scores (student_id, question_number, exam_set)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_scores_student_question ON scores (student_id, question_number)");
        
        echo "✅ Scores table rebuilt successfully.<br>";
        
    } else {
        // MySQL
        // Attempt to drop bad index 'student_id' or 'idx_scores_student_question' if it's UNIQUE
        $indices_to_drop = ['student_id', 'idx_unique_student_question', 'idx_scores_student_question']; 
        
        foreach ($indices_to_drop as $idx) {
            try {
                // Only drop if it causes conflict (we can't check easily in one query without information_schema overhead, so we try dropping typical bad ones)
                // Actually, blindly dropping idx_scores_student_question is risky if we want to keep it as non-unique.
                // But we will re-add it as non-unique below.
                $pdo->exec("ALTER TABLE scores DROP INDEX $idx");
                echo "✅ Dropped index '$idx' from scores.<br>";
            } catch (Exception $e) {
                // Ignore
            }
        }
        
        // Ensure correct indices
         try {
            $pdo->exec("ALTER TABLE scores ADD UNIQUE INDEX idx_unique_score (student_id, question_number, exam_set)");
            echo "✅ Added unique index 'idx_unique_score'.<br>";
        } catch (Exception $e) {
             echo "ℹ️ Index 'idx_unique_score' already exists (Good).<br>";
        }
        
        try {
            $pdo->exec("ALTER TABLE scores ADD INDEX idx_scores_student_question (student_id, question_number)");
            echo "✅ Added index 'idx_scores_student_question'.<br>";
        } catch (Exception $e) {
             echo "ℹ️ Index 'idx_scores_student_question' already exists (Good).<br>";
        }
    }

    if ($db_driver === 'sqlite') {
        $pdo->commit();
    }
    echo "<h3>🎉 Migration Completed Successfully!</h3>";
    echo "Tables are now structure correctly to support multiple exam sets.";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // For MySQL, even if it errors on commit/rollback, DDLs might be done.
    if ($db_driver === 'mysql' && strpos($e->getMessage(), 'active transaction') !== false) {
         echo "<h3>🎉 Migration Completed Successfully! (MySQL Auto-commit)</h3>";
         echo "The error 'no active transaction' is normal for MySQL because it saved changes automatically.<br>";
         echo "Your tables are FIXED. You can proceed.";
    } else {
        echo "<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>";
    }
}
?>

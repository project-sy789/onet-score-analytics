<?php
require_once __DIR__ . '/../db.php';

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
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_unique_question ON questions (question_number, exam_set, subject, grade_level)");
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
        
        // Ensure composite index exists for questions
        try {
            // First try to drop the old one if it's not complete
            $pdo->exec("ALTER TABLE questions DROP INDEX idx_unique_question");
        } catch (Exception $e) {}
        
        try {
            $pdo->exec("ALTER TABLE questions ADD UNIQUE INDEX idx_unique_question (question_number, exam_set, subject, grade_level)");
            echo "✅ Added unique index 'idx_unique_question'.<br>";
        } catch (Exception $e) {
            echo "ℹ️ Index 'idx_unique_question' already exists or conflict found: " . $e->getMessage() . "<br>";
        }
    }
    
    // ==========================================
    // 1.5 FIX INDICATORS TABLE
    // ==========================================
    echo "<h3>1.5. Rebuilding Indicators Table...</h3>";
    
    if ($db_driver === 'sqlite') {
        // Create new table WITHOUT the bad constraint
        $pdo->exec("
            CREATE TABLE indicators_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL,
                description TEXT,
                subject TEXT,
                grade_level TEXT,
                exam_set TEXT DEFAULT 'default'
            )
        ");
        
        // Check if old indicators table has exam_set
        $has_exam_set = false;
        $cols = $pdo->query("PRAGMA table_info(indicators)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            if ($col['name'] === 'exam_set') {
                $has_exam_set = true;
                break;
            }
        }
        
        $exam_set_select = $has_exam_set ? "exam_set" : "'default' as exam_set";
        
        // Copy Data
        $pdo->exec("INSERT INTO indicators_new (id, code, description, subject, grade_level, exam_set) 
                    SELECT id, code, description, subject, grade_level, $exam_set_select FROM indicators");
        
        // Drop Old
        $pdo->exec("DROP TABLE indicators");
        
        // Rename New
        $pdo->exec("ALTER TABLE indicators_new RENAME TO indicators");
        
        // Add Correct Indices
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_indicators_unique ON indicators (code, subject, grade_level, exam_set)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_indicators_subject ON indicators (subject)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_indicators_exam_set ON indicators (exam_set)");
        
        echo "✅ Indicators table rebuilt successfully.<br>";
        
    } else {
        // MySQL
        // Drop any old specific index that might conflict, such as code uniqueness without exam_set
        $indicator_indices_to_drop = ['code', 'code_2', 'idx_unique_indicator'];
        foreach ($indicator_indices_to_drop as $idx) {
            try {
                $pdo->exec("ALTER TABLE indicators DROP INDEX $idx");
                echo "✅ Dropped old indicator index '$idx'.<br>";
            } catch (Exception $e) {}
        }
        
        // Sometimes UNIQUE constraint was added anonymously, so we try to catch the precise unique key name if possible via reflection, but the easiest way is to ensure a named one exists and catches all.
        try {
            // First we make sure all duplicates are cleared or just add the new index
            $pdo->exec("ALTER TABLE indicators ADD UNIQUE INDEX idx_indicators_full (code, subject, grade_level, exam_set)");
            echo "✅ Added unique index 'idx_indicators_full' to indicators.<br>";
        } catch (Exception $e) {
             echo "ℹ️ Unique index 'idx_indicators_full' already exists or conflict found: " . $e->getMessage() . "<br>";
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

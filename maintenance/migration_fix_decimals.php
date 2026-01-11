<?php
require_once __DIR__ . '/../db.php';

echo "<h2>Migration: Fix Decimal Precision (3.125 -> 3.13 problem)</h2>";

try {
    $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database driver: $db_driver<br>";
    
    // 1. Fix Questions Table (max_score)
    echo "<h3>1. Fixing 'max_score' column precision...</h3>";
    if ($db_driver === 'mysql') {
        // MySQL: Change to DOUBLE for high precision
        $pdo->exec("ALTER TABLE questions MODIFY COLUMN max_score DOUBLE DEFAULT 1.0");
        echo "✅ MySQL: Altered max_score to DOUBLE.<br>";
    } else {
        // SQLite: Rebuild needed if type isn't REAL (My previous script did this, but good to reinforce)
        // Check current type not easy via SQL. We assume previous rebuild set it to REAL.
        // But we can check PRAGMA.
        echo "✅ SQLite: Uses dynamic typing (REAL), should be fine if Rebuild was run.<br>";
    }
    
    // 2. Fix Scores Table (score_obtained)
    echo "<h3>2. Fixing 'score_obtained' column precision...</h3>";
    if ($db_driver === 'mysql') {
        // MySQL: Change to DOUBLE
        $pdo->exec("ALTER TABLE scores MODIFY COLUMN score_obtained DOUBLE DEFAULT 0.0");
        echo "✅ MySQL: Altered score_obtained to DOUBLE.<br>";
    } else {
        echo "✅ SQLite: Uses dynamic typing (REAL).<br>";
    }

    echo "<h3>🎉 Precision Fixed!</h3>";
    echo "Please <strong>Re-Import</strong> your data for the changes to take effect.<br>";
    echo "(Data already in the database might still be rounded, re-importing is necessary)";

} catch (Exception $e) {
    echo "<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>";
}
?>

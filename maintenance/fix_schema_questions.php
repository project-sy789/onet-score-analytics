<?php
// Enable error reporting to prevent blank page
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Try to load db.php from parent or current dir
if (file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
} elseif (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} else {
    die("Error: Could not find db.php. Please copy this script to the 'maintenance' folder or the root folder.");
}

echo "<h1>Fixing Database Schema for Multi-Grade Exam Sets</h1>";

try {
    // 1. Check if we are MySQL or SQLite
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database Drive: $driver<br>";

    // 1.5 Debug: Show current indexes
    echo "<h3>Current Table Structure:</h3>";
    if ($driver === 'mysql') {
        $stmt = $pdo->query("SHOW CREATE TABLE questions");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<pre>" . htmlspecialchars($row['Create Table']) . "</pre>";
        
        echo "<h4>Indexes:</h4>";
        $stmt = $pdo->query("SHOW INDEX FROM questions");
        echo "<table border=1><tr><th>Key_name</th><th>Column_name</th><th>Seq_in_index</th><th>Non_unique</th></tr>";
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr><td>{$r['Key_name']}</td><td>{$r['Column_name']}</td><td>{$r['Seq_in_index']}</td><td>{$r['Non_unique']}</td></tr>";
        }
        echo "</table><br>";
    }

    // 2. Drop existing index
    echo "Dropping old index 'idx_unique_question'... ";
    try {
        if ($driver === 'mysql') {
            // Try dropping by name associated with the unique constraint
            $pdo->exec("DROP INDEX idx_unique_question ON questions");
        } else {
            $pdo->exec("DROP INDEX IF EXISTS idx_unique_question");
        }
        echo "<strong style='color:green'>Done</strong><br>";
    } catch (Exception $e) {
        // Index might not exist or verify name
        echo "<strong style='color:orange'>Warning: " . $e->getMessage() . "</strong> (Proceeding)<br>";
    }

    // 3. Create new index
    echo "Creating new unique index (question_number, exam_set, grade_level)... ";
    try {
        if ($driver === 'mysql') {
             $sql = "CREATE UNIQUE INDEX idx_unique_question ON questions (question_number, exam_set, grade_level)";
        } else {
            $sql = "CREATE UNIQUE INDEX idx_unique_question ON questions (question_number, exam_set, grade_level)";
        }
        
        $pdo->exec($sql);
        echo "<strong style='color:green'>Success!</strong><br>";
    } catch (Exception $e) {
         echo "<strong style='color:red'>Creation Error: " . $e->getMessage() . "</strong><br>";
    }
    
    echo "<hr>";
    echo "<h3>Schema Updated Successfully. You can now import M.3 and M.6 with same Exam Set name.</h3>";

} catch (Exception $e) {
    echo "<h3 style='color:red'>Error: " . $e->getMessage() . "</h3>";
    echo "<p>Please verify if the index name is correct or if the table is locked.</p>";
}
?>
<br>
<a href="../import.php">Back to Import</a>

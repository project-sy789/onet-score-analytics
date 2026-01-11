<?php
require_once 'db.php';

echo "<h2>Data Integrity Check</h2>";

try {
    // 1. Check Questions Duplicates
    echo "<h3>Questions Duplicates (question_number + exam_set)</h3>";
    $sql = "
        SELECT question_number, exam_set, COUNT(*) as c 
        FROM questions 
        GROUP BY question_number, exam_set 
        HAVING c > 1
    ";
    $stmt = $pdo->query($sql);
    $dupes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($dupes) > 0) {
        echo "<div style='color:red'>Found " . count($dupes) . " sets of duplicate questions!</div>";
        foreach ($dupes as $d) {
            echo "Q: {$d['question_number']}, Set: {$d['exam_set']} (Count: {$d['c']})<br>";
        }
    } else {
        echo "<div style='color:green'>No duplicate questions found.</div>";
    }

    // 2. Check Scores Duplicates
    echo "<h3>Scores Duplicates (student_id + question_number + exam_set)</h3>";
    $sql = "
        SELECT student_id, question_number, exam_set, COUNT(*) as c 
        FROM scores 
        GROUP BY student_id, question_number, exam_set 
        HAVING c > 1
    ";
    $stmt = $pdo->query($sql);
    $dupes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($dupes) > 0) {
        echo "<div style='color:red'>Found " . count($dupes) . " sets of duplicate scores!</div>";
        echo "Example duplicates:<br>";
        $limit = 0;
        foreach ($dupes as $d) {
            echo "Student: {$d['student_id']}, Q: {$d['question_number']}, Set: {$d['exam_set']} (Count: {$d['c']})<br>";
            $limit++;
            if ($limit >= 10) { echo "... and more ...<br>"; break; }
        }
    } else {
        echo "<div style='color:green'>No duplicate scores found.</div>";
    }
    
    // 3. Show Schema Indices
    echo "<h3>Table Indices</h3>";
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        echo "<b>Scores Indices:</b><br>";
        $stmt = $pdo->query("PRAGMA index_list(scores)");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "Index: {$row['name']} (Unique: {$row['unique']})<br>";
        }
        echo "<br><b>Questions Indices:</b><br>";
        $stmt = $pdo->query("PRAGMA index_list(questions)");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "Index: {$row['name']} (Unique: {$row['unique']})<br>";
        }
    } else {
        // MySQL
        echo "<b>Scores Indices:</b><br>";
        $stmt = $pdo->query("SHOW INDEX FROM scores");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "Key: {$row['Key_name']} (Column: {$row['Column_name']})<br>";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>

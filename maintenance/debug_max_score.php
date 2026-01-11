<?php
if (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
} else {
    die("Error: db.php not found in " . __DIR__ . " or parent directory.");
}

echo "<h2>Debug: Check Max Score Precision</h2>";

try {
    $db_driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database driver: <b>$db_driver</b><br>";
    
    // 1. Check Column Type
    echo "<h3>1. Column Definition (questions table)</h3>";
    if ($db_driver === 'mysql') {
        $stmt = $pdo->query("DESCRIBE questions max_score");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Type: " . ($row['Type'] ?? 'Unknown') . "<br>";
    } else {
        $stmt = $pdo->query("PRAGMA table_info(questions)");
        $found = false;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['name'] === 'max_score') {
                echo "Type: " . $row['type'] . "<br>";
                $found = true;
                break;
            }
        }
        if (!$found) echo "Column 'max_score' not found!<br>";
    }

    // 2. Check Raw Values
    echo "<h3>2. Raw Values in Database (First 5 questions with decimals)</h3>";
    
    // Try to find questions that are not integers if possible
    $stmt = $pdo->query("SELECT id, question_number, exam_set, max_score FROM questions WHERE max_score != ROUND(max_score) LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($rows)) {
        echo "No decimal max_scores found. Showing first 5 records:<br>";
        $stmt = $pdo->query("SELECT id, question_number, exam_set, max_score FROM questions LIMIT 5");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Q#</th><th>Set</th><th>Raw Value (PHP)</th><th>Formatted (4 decimals)</th></tr>";
    foreach ($rows as $row) {
        $raw = $row['max_score'];
        $formatted = number_format($raw, 4);
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['question_number']}</td>";
        echo "<td>{$row['exam_set']}</td>";
        echo "<td>" . var_export($raw, true) . "</td>";
        echo "<td>$formatted</td>";
        echo "</tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
